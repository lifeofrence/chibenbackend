<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Mail\BookingConfirmed;
use App\Mail\NewBookingNotification;
use Illuminate\Support\Facades\Log;


class PaymentController extends Controller
{
    public function initiate(Request $request)
    {
        $data = $request->validate([
            'booking_id' => 'required|exists:bookings,id',
        ]);

        $booking = Booking::findOrFail($data['booking_id']);

        // Generate a unique reference and store on booking
        $reference = 'PAY-' . Str::upper(Str::random(12));
        $booking->update(['payment_reference' => $reference]);

        // Paystack requires amount in kobo (NGN minor unit)
        $amountKobo = (int) round(($booking->amount ?? 0) * 100);
        if ($amountKobo <= 0) {
            return response()->json([
                'message' => 'Invalid booking amount for payment.',
            ], 422);
        }

        $frontendBase = config('services.frontend.base_url', 'https://www.chibenhotels.com');
        $callbackUrl = rtrim($frontendBase, '/') . '/payment/callback?booking_id=' . $booking->id;

        $payload = [
            'email' => $booking->guest_email,
            'amount' => $amountKobo,
            'reference' => $reference,
            'currency' => config('services.paystack.currency', 'NGN'),
            'callback_url' => $callbackUrl,
            'metadata' => [
                'booking_id' => $booking->id,
            ],
        ];

        $resp = Http::withToken(config('services.paystack.secret'))
            ->post('https://api.paystack.co/transaction/initialize', $payload);

        if ($resp->failed() || !($resp->json('status'))) {
            $message = $resp->json('message') ?? 'Failed to initialize Paystack transaction.';
            return response()->json([
                'message' => $message,
            ], 502);
        }

        $authUrl = $resp->json('data.authorization_url');

        Transaction::create([
            'booking_id' => $booking->id,
            'reference' => $reference,
            'gateway' => 'paystack',
            'amount' => $booking->amount,
            'currency' => config('services.paystack.currency', 'NGN'),
            'status' => 'initiated',
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Payment session initiated.',
            'payment_reference' => $reference,
            'authorization_url' => $authUrl,
            'amount' => $booking->amount,
            'booking' => $booking,
        ]);
    }

    public function confirm(Request $request)
    {
        $ref = $request->input('payment_reference', $request->input('reference'));

        if (!$ref || !is_string($ref)) {
            return response()->json(['message' => 'Missing payment reference.'], 400);
        }

        $bookingId = $request->input('booking_id');
        $booking = $bookingId
            ? Booking::find($bookingId)
            : Booking::where('payment_reference', $ref)->first();

        if (!$booking) {
            return response()->json(['message' => 'Booking not found for the given reference.'], 404);
        }

        // Verify transaction via Paystack API
        $resp = Http::withToken(config('services.paystack.secret'))
            ->get('https://api.paystack.co/transaction/verify/' . urlencode($ref));

        if ($resp->failed()) {
            return response()->json([
                'message' => 'Failed to verify payment with Paystack.',
            ], 502);
        }

        $dataStatus = $resp->json('data.status');
        $gatewayResponse = $resp->json('data.gateway_response');

        // Log transaction
        Transaction::create([
            'booking_id' => $booking->id,
            'reference' => $ref,
            'gateway' => 'paystack',
            'amount' => $booking->amount,
            'currency' => config('services.paystack.currency', 'NGN'),
            'status' => $dataStatus === 'success' ? 'success' : $dataStatus,
            'gateway_response' => $resp->json('data'),
            'ip_address' => $request->ip(),
        ]);

        if ($dataStatus === 'success') {
            // Ensure booking stores the correct reference
            if (!$booking->payment_reference) {
                $booking->payment_reference = $ref;
            }
            $booking->status = 'confirmed';
            $booking->save();

              // Load relations for email and response payload
            $bookingWithRelations = $booking->load('roomType', 'room');

            // Send emails to guest and admin
            try {
                Mail::to($booking->guest_email)->send(new BookingConfirmed($bookingWithRelations));
                $adminEmail = 'lifeofrence@gmail.com';
                if ($adminEmail) {
                    Mail::to($adminEmail)->send(new NewBookingNotification($bookingWithRelations));
                }
            } catch (\Throwable $e) {
                Log::error('Booking email send failed', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Payment confirmed.',
                'bookings' => $bookingWithRelations,
                'booking' => $booking,
            ]);


        }

        // Treat non-fatal statuses as pending; don't cancel or release room yet
        if (in_array($dataStatus, ['abandoned', 'timeout', 'pending'], true)) {
            if (!$booking->payment_reference) {
                $booking->payment_reference = $ref;
            }
            $booking->status = 'pending';
            $booking->save();

            return response()->json([
                'message' => 'Payment not completed (' . $dataStatus . '). Booking remains pending.',
                'gateway_status' => $dataStatus,
                'gateway_response' => $gatewayResponse,
                'booking' => $booking,
            ], 202);
        }

        // Failed or unexpected outcome: cancel booking and release room
        $booking->status = 'cancelled';
        $booking->save();

        if ($booking->room_id) {
            $room = Room::find($booking->room_id);
            if ($room) {
                $room->status = 'Available';
                $room->save();
            }
        }

        return response()->json([
            'message' => 'Payment verification not successful (' . $dataStatus . '). Booking cancelled and room released.',
            'gateway_status' => $dataStatus,
            'gateway_response' => $gatewayResponse,
            'booking' => $booking,
        ], 400);
    }

    public function webhook(Request $request)
    {
        // Verify Paystack signature
        $signature = $request->header('X-Paystack-Signature');
        $secret = config('services.paystack.secret');
        $computed = hash_hmac('sha512', $request->getContent(), $secret);
        if (!$signature || !hash_equals($signature, $computed)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $event = $request->input('event');
        $data = $request->input('data', []);
        $reference = $data['reference'] ?? null;
        $status = $data['status'] ?? null;

        if ($reference) {
            $booking = Booking::where('payment_reference', $reference)->first();

            Transaction::create([
                'booking_id' => $booking?->id,
                'reference' => $reference,
                'gateway' => 'paystack',
                'amount' => $booking?->amount ?? 0,
                'currency' => config('services.paystack.currency', 'NGN'),
                'status' => $status ?? $event,
                'gateway_response' => $data,
                'ip_address' => $request->ip(),
            ]);

            if ($booking) {
                if ($event === 'charge.success' || $status === 'success') {
                    $booking->status = 'confirmed';
                    $booking->save();
                } elseif ($status === 'failed') {
                    $booking->status = 'cancelled';
                    $booking->save();
                    if ($booking->room_id) {
                        $room = Room::find($booking->room_id);
                        if ($room) {
                            $room->status = 'Available';
                            $room->save();
                        }
                    }
                } elseif (in_array($status, ['abandoned', 'timeout', 'pending'], true)) {
                    // Keep pending on non-fatal statuses; do not release room
                    $booking->status = 'pending';
                    $booking->save();
                }
            }
        }

        return response()->json(['message' => 'Webhook processed']);
    }
}