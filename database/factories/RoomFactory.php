<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoomFactory extends Factory
{
    // ربط الفاكتوري بالموديل الصحيح
    protected $model = Room::class;

    public function definition(): array
    {
        return [
            'branch_id'       => Branch::factory(),
            'room_number'     => $this->faker->unique()->numberBetween(100, 999),
            'type'            => $this->faker->randomElement(['Single', 'Double', 'Suite']),
            'status'          => 'available',
            'price_per_night' => $this->faker->randomFloat(2, 100, 1000),
            'description'     => $this->faker->sentence(),
        ];
    }
}