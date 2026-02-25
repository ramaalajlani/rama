<?php

namespace Database\Factories;

use App\Models\Guest;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

class GuestFactory extends Factory
{
    protected $model = Guest::class;

    public function definition(): array
    {
        return [
            'branch_id'      => Branch::factory(), // ربط النزيل بفرع تلقائياً
            'full_name'      => $this->faker->name(), // تم التعديل من name إلى full_name
            'nationality'    => $this->faker->country(),
            'id_card_number' => $this->faker->numerify('##########'),
            'passport_number'=> $this->faker->bothify('??######'),
            'phone'          => $this->faker->phoneNumber(),
            'notes'          => $this->faker->sentence(),
        ];
    }
}