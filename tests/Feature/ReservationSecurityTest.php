<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Reservation;
use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use PHPUnit\Framework\Attributes\Test;

class ReservationSecurityTest extends TestCase
{
    use RefreshDatabase; // تنظيف قاعدة البيانات بعد كل اختبار

    protected function setUp(): void
    {
        parent::setUp();
        
        // 1. إعادة ضبط الكاش الخاص بالصلاحيات لضمان نظافة الاختبار
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // 2. إعداد الأدوار والصلاحيات الأساسية
        $lockPermission = Permission::create(['name' => 'lock_stays']);
        
        $receptionistRole = Role::create(['name' => 'receptionist']);
        
        $supervisorRole = Role::create(['name' => 'hq_supervisor']);
        $supervisorRole->givePermissionTo($lockPermission);

        $adminRole = Role::create(['name' => 'hq_admin']);
        $adminRole->givePermissionTo($lockPermission);
    }

    /** @test */
    public function receptionist_cannot_update_locked_reservation()
    {
        // إنشاء فرع وموظف وحجز مقفل في نفس الفرع
        $branch = Branch::create(['name' => 'Branch A', 'status' => 'active']);
        $user = User::factory()->create(['branch_id' => $branch->id, 'status' => 'active']);
        $user->assignRole('receptionist');

        $reservation = Reservation::factory()->create([
            'branch_id' => $branch->id,
            'is_locked' => true 
        ]);

        $response = $this->actingAs($user)
                         ->putJson("/api/reservations/{$reservation->id}", [
                             'status' => 'confirmed'
                         ]);

        // يجب أن يراه (لأنه في فرعه) ولكن يمنعه من التعديل لأنه مقفل
        $response->assertStatus(403);
    }


    public function user_cannot_see_reservations_from_other_branches()
    {
        $branchA = Branch::create(['name' => 'Branch A', 'status' => 'active']);
        $branchB = Branch::create(['name' => 'Branch B', 'status' => 'active']);
        
        $user = User::factory()->create(['branch_id' => $branchA->id, 'status' => 'active']);
        $user->assignRole('receptionist');

        $reservationInBranchB = Reservation::factory()->create(['branch_id' => $branchB->id]);

        $response = $this->actingAs($user)
                         ->getJson("/api/reservations/{$reservationInBranchB->id}");

        // التوقع 404 صحيح هنا لأن الموظف لا يرى بيانات خارج فرعه
        $response->assertStatus(404);
    }

    /** @test */
    public function only_authorized_users_can_lock_reservations()
    {
        // التعديل الجوهري هنا:
        // 1. إنشاء فرع نشط
        $branch = Branch::create(['name' => 'Branch Test', 'status' => 'active']);

        // 2. إنشاء موظف في هذا الفرع
        $user = User::factory()->create([
            'branch_id' => $branch->id,
            'status' => 'active'
        ]);
        $user->assignRole('receptionist');

        // 3. إنشاء حجز في نفس الفرع (ليتمكن الموظف من رؤيته أولاً)
        $reservation = Reservation::factory()->create([
            'branch_id' => $branch->id
        ]);

        // 4. محاولة القفل
        $response = $this->actingAs($user)
                         ->patchJson("/api/reservations/{$reservation->id}/lock");

        // الآن سيعيد 403 (Forbidden) بدلاً من 404
        // لأنه وجد الحجز (findOrFail نجحت) ولكن الـ Policy رفضت القفل
        $response->assertStatus(403);
    }
}