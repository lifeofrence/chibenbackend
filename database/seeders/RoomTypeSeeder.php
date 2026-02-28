<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\RoomType;
use Illuminate\Support\Facades\DB;

class RoomTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roomTypes = [
            [
                'id' => 1,
                'name' => 'Studio Room',
                'description' => 'Cozy and elegant studio room perfect for solo travelers needing a comfortable rest.',
                'base_price' => 30000,
            ],
            [
                'id' => 2,
                'name' => 'Standard Room',
                'description' => 'Essential comfort delivered with the warmth of genuine hospitality. Perfect for couples.',
                'base_price' => 70000,
            ],
            [
                'id' => 3,
                'name' => 'Deluxe Room',
                'description' => 'Refined luxury with premium bedding and enhanced amenities for a superior stay.',
                'base_price' => 80000,
            ],
            [
                'id' => 4,
                'name' => 'Premium Room',
                'description' => 'Superior comfort with enhanced space and premium views of Awka.',
                'base_price' => 90000,
            ],
            [
                'id' => 5,
                'name' => 'Mini Suite',
                'description' => 'A spacious junior suite featuring a separate living area and VIP services.',
                'base_price' => 100000,
            ],
            [
                'id' => 6,
                'name' => 'Royal Suite',
                'description' => 'Deluxe suite offering gourmet amenities and a boutique setting unlike any other.',
                'base_price' => 150000,
            ],
            [
                'id' => 7,
                'name' => 'Royal Classic Suite',
                'description' => 'Premium suite with exquisite design and a private bar for the ultimate relaxation.',
                'base_price' => 200000,
            ],
            [
                'id' => 8,
                'name' => 'Executive Apartment',
                'description' => 'Luxury apartment featuring a full kitchen and spacious living room, ideal for families.',
                'base_price' => 200000,
            ],
            [
                'id' => 9,
                'name' => 'Presidential Apartment',
                'description' => 'Unparalleled luxury across vast space with premium amenities and personalized service.',
                'base_price' => 300000,
            ],
        ];

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Remove room types that are not in our list
        $ids = array_column($roomTypes, 'id');
        RoomType::whereNotIn('id', $ids)->delete();

        foreach ($roomTypes as $data) {
            RoomType::updateOrCreate(
                ['id' => $data['id']],
                [
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'base_price' => $data['base_price'],
                ]
            );
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }
}
