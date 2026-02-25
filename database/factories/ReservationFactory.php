<?php

namespace Database\Factories;

use App\Models\Reservation;
use App\Models\Branch;
use App\Models\User;
use App\Models\Guest; // تأكد من وجود هذا السطر
use App\Models\Room;  // تأكد من وجود هذا السطر
use Illuminate\Database\Eloquent\Factories\Factory;

class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    public function definition(): array
    {
        return [
            'branch_id'    => Branch::factory(),
            'user_id'      => User::factory(),
            'guest_id'     => Guest::factory(), // الآن سيعرف أنه موديل
            'room_id'      => Room::factory(),  // الآن سيعرف أنه موديل
            'check_in'     => now()->addDays(1),
            'check_out'    => now()->addDays(3),
            'is_locked'    => false,
            'total_amount' => $this->faker->randomFloat(2, 200, 2000), 
            'status'       => 'pending',
        ];
 
        }
}