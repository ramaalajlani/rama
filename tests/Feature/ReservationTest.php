<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Guest;
use App\Models\Room;
use App\Models\Branch;
use Spatie\Permission\Models\Role; // استيراد موديل الأدوار
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // مسح الكاش
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        
        // إنشاء الأدوار المطلوبة في الـ Policy
        Role::create(['name' => 'receptionist', 'guard_name' => 'web']);
        Role::create(['name' => 'hq_admin', 'guard_name' => 'web']);
    }

    #[Test]
    public function authorized_user_can_create_reservation()
    {
        $branch = Branch::create(['name' => 'Main Branch']);
        
        // إنشاء مستخدم ومنحه دور 'receptionist' ليتوافق مع الـ Policy
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->assignRole('receptionist'); 

        $guest = Guest::create([
            'branch_id'   => $branch->id, 
            'full_name'   => 'John Doe', 
            'phone'       => '123456', 
            'nationality' => 'Syrian'
        ]);

        $room = Room::create([
            'branch_id'       => $branch->id, 
            'room_number'     => '101', 
            'type'            => 'Single', 
            'price_per_night' => 100, 
            'status'          => 'available'
        ]);

        $response = $this->actingAs($user)->postJson('/api/reservations', [
            'guest_id'  => $guest->id,
            'room_id'   => $room->id,
            'check_in'  => now()->addDay()->format('Y-m-d H:i:s'),
            'check_out' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ]);

        // الآن سيعطيك 201 لأن المستخدم أصبح 'receptionist' فعلياً
        $response->assertStatus(201);
    }

    #[Test]
    public function unauthorized_user_cannot_create_reservation()
    {
        $branch = Branch::create(['name' => 'Main Branch']);
        
        // مستخدم بدون أي دور
        $user = User::factory()->create(['branch_id' => $branch->id]);

        $guest = Guest::create(['branch_id' => $branch->id, 'full_name' => 'G2', 'phone' => '1', 'nationality' => 'S']);
        $room = Room::create(['branch_id' => $branch->id, 'room_number' => '102', 'type' => 'S', 'price_per_night' => 10, 'status' => 'available']);

        $response = $this->actingAs($user)->postJson('/api/reservations', [
            'guest_id'  => $guest->id,
            'room_id'   => $room->id,
            'check_in'  => now()->addDay()->format('Y-m-d H:i:s'),
            'check_out' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ]);

        // سينجح الاختبار هنا لأنه سيعطي 403 (لعدم وجود الدور)
        $response->assertStatus(403);
    }
}