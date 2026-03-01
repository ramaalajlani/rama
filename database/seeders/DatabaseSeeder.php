<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

use App\Models\{
    User,
    Branch,
    Room,
    Guest,
    SecurityBlacklist,
    Reservation
};
use Spatie\Permission\Models\{Role, Permission};
use Carbon\Carbon;

class DatabaseSeeder extends Seeder
{
    /**
     * تشغيل بذور قاعدة البيانات - الإصدار الأمني المتكامل 2026
     */
    public function run(): void
    {
        // 1) تنظيف كاش الصلاحيات
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = 'api';

        // 2) الصلاحيات
        $permissions = [
            'manage_branches',      'manage_users',
            'lock_stays',           'unlock_stays',
            'view_security_docs',   'view_audit_logs',
            'view_all_branches',    'write_security_notes',
            'create_guests',        'view_guests',
            'view_blacklist',       'manage_blacklist',
            'view_notifications',   'mark_notifications_read',
            'verify_security_hashes'
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => $guard]);
        }

        // 3) الأدوار
        $adminRole = Role::firstOrCreate(['name' => 'hq_admin', 'guard_name' => $guard]);
        $adminRole->syncPermissions(Permission::where('guard_name', $guard)->get());

        $receptionistRole = Role::firstOrCreate(['name' => 'branch_reception', 'guard_name' => $guard]);
        // ملاحظة: خليك على الصلاحيات اللي عندك، أو عدّلها حسب نظامك
        $receptionistRole->syncPermissions(['create_guests', 'view_guests', 'mark_notifications_read']);

        // 4) إنشاء الفرع التجريبي (✅ إضافة city لتجنب خطأ seed)
        $branch1 = Branch::updateOrCreate(
            ['name' => 'Four Seasons - Damascus'],
            [
                'city'    => 'Damascus',                 // ✅ مهم
                'address' => 'Damascus - Abu Rummaneh',
                'status'  => 'active',
            ]
        );

        // 5) إنشاء المستخدمين
        $hqMaster = User::updateOrCreate(
            ['email' => 'user3@gmail.com'],
            [
                'name'     => 'System Master Admin',
                'password' => Hash::make('123456789'),
                'status'   => 'active',
            ]
        );
        $hqMaster->syncRoles([$adminRole]);

        $receptionistUser = User::updateOrCreate(
            ['email' => 'user1@gmail.com'],
            [
                'name'      => 'Ahmed Receptionist',
                'password'  => Hash::make('123456789'),
                'branch_id' => $branch1->id,
                'status'    => 'active',
            ]
        );
        $receptionistUser->syncRoles([$receptionistRole]);

        // ==========================================================
        // ✅ إضافة مدقق HQ حسب الوثيقة (Read-only) — بدون لمس القديم
        // ==========================================================
        $auditorRole = Role::firstOrCreate(['name' => 'hq_auditor', 'guard_name' => $guard]);
        $auditorRole->syncPermissions([
            'view_audit_logs',
            'view_all_branches',
            'view_guests',
            'view_notifications',
            'mark_notifications_read',
        ]);

        $hqAuditor = User::updateOrCreate(
            ['email' => 'user2@gmail.com'],
            [
                'name'     => 'HQ Auditor',
                'password' => Hash::make('123456789'),
                'status'   => 'active',
            ]
        );
        $hqAuditor->syncRoles([$auditorRole]);
        // ==========================================================

        // ✅ (اختياري مفيد) إنشاء دور أمن HQ إذا حابة تشتغلي عليه لاحقاً
        // (ما رح يأثر على شي حتى لو ما استخدمتيه حالياً)
        $securityRole = Role::firstOrCreate(['name' => 'hq_security', 'guard_name' => $guard]);
        $securityRole->syncPermissions([
            'view_blacklist',
            'manage_blacklist',
            'view_notifications',
            'view_all_branches',
            'view_guests',
            'verify_security_hashes',
        ]);

        // 6) محرك الهاش الأمني
        $salt = (string) config('app.key');

        $normalize = function (?string $value): string {
            $v = trim((string)$value);
            $v = str_replace(' ', '', $v);

            $search  = ['أ','إ','آ','ة','ى','ؤ','ئ','ء'];
            $replace = ['ا','ا','ا','ه','ي','و','ي',''];
            $v = str_replace($search, $replace, $v);

            return Str::lower($v);
        };

        $generateHash = function (?string $val) use ($salt, $normalize) {
            $val = trim((string)$val);
            if ($val === '') return null;
            return hash('sha256', $normalize($val) . $salt);
        };

        // 7) بيانات القائمة السوداء
        SecurityBlacklist::updateOrCreate(
            ['identity_hash' => (string)$generateHash('9876543210')],
            [
                'full_name_hash'    => $generateHash('SamerAliHassan'),
                'father_name_hash'  => $generateHash('Ali'),
                'mother_name_hash'  => $generateHash('Faten'),
                'triple_check_hash' => $generateHash('SamerAliFaten'),
                'full_hash'         => $generateHash('SamerAliHassanFaten'),
                'risk_level'        => 'CRITICAL',
                'reason'            => 'مطلوب بموجب تعميم أمني رقم 405',
                'instructions'      => 'قفل ملف الحجز فوراً وإبلاغ الأمن المركزي بهدوء.',
                'is_active'         => true,
                'created_by'        => $hqMaster->id,
            ]
        );

        // 8) إضافة عدة غرف
        $rooms = [
            ['room_number' => '101', 'floor_number' => 1, 'type' => 'suite',   'status' => 'available'],
            ['room_number' => '102', 'floor_number' => 1, 'type' => 'double',  'status' => 'available'],
            ['room_number' => '103', 'floor_number' => 1, 'type' => 'single',  'status' => 'available'],
            ['room_number' => '201', 'floor_number' => 2, 'type' => 'suite',   'status' => 'available'],
            ['room_number' => '202', 'floor_number' => 2, 'type' => 'double',  'status' => 'available'],
            ['room_number' => '203', 'floor_number' => 2, 'type' => 'single',  'status' => 'maintenance'],
            ['room_number' => '301', 'floor_number' => 3, 'type' => 'suite',   'status' => 'available'],
            ['room_number' => '302', 'floor_number' => 3, 'type' => 'double',  'status' => 'available'],
        ];

        foreach ($rooms as $r) {
            Room::updateOrCreate(
                ['room_number' => $r['room_number'], 'branch_id' => $branch1->id],
                [
                    'type'         => $r['type'],
                    'status'       => $r['status'],
                    'floor_number' => $r['floor_number'],
                ]
            );
        }

        // 9) نزيل اختباري
        $testId = '1234567890';
        Guest::updateOrCreate(
            ['national_id' => $testId],
            [
                'first_name'   => 'John',
                'father_name'  => 'Edward',
                'last_name'    => 'Doe',
                'mother_name'  => 'Jane',
                'id_type'      => 'national_id',
                'nationality'  => 'Syrian',
                'phone'        => '0930111222',
                'is_flagged'   => false,
                'status'       => 'active',
                'audit_status' => 'new',
            ]
        );

        /**
         * ==========================================================
         * 10) ✅ Seed إقامات (حجوزات) لتعبئة الداشبورد
         *     (بدون تعديل status للغرفة لتجنب ENUM error)
         * ==========================================================
         */
        $today = Carbon::today();
        $t0    = $today->copy()->startOfDay();

        $roomsAvail = Room::query()
            ->where('branch_id', $branch1->id)
            ->where('status', '!=', 'maintenance')
            ->orderBy('id')
            ->get();

        $makeGuest = function (array $g): Guest {
            return Guest::updateOrCreate(
                ['national_id' => (string)$g['national_id']],
                [
                    'first_name'   => $g['first_name'],
                    'father_name'  => $g['father_name'] ?? null,
                    'last_name'    => $g['last_name'],
                    'mother_name'  => $g['mother_name'] ?? null,
                    'id_type'      => $g['id_type'] ?? 'national_id',
                    'nationality'  => $g['nationality'] ?? 'Syrian',
                    'phone'        => $g['phone'] ?? null,
                    'is_flagged'   => (bool)($g['is_flagged'] ?? false),
                    'status'       => $g['status'] ?? 'active',
                    'audit_status' => $g['audit_status'] ?? 'new',
                ]
            );
        };

        $createReservation = function (array $payload) use ($branch1, $receptionistUser, $roomsAvail) {
            $roomNo = (string)($payload['room_number'] ?? '101');
            $room = $roomsAvail->firstWhere('room_number', $roomNo) ?? $roomsAvail->first();

            $isLocked = (bool)($payload['is_locked'] ?? false);

            $reservation = Reservation::updateOrCreate(
                [
                    'room_id'   => $room->id,
                    'branch_id' => $branch1->id,
                    'check_in'  => $payload['check_in'],
                ],
                [
                    'user_id'          => $receptionistUser->id,
                    'check_out'        => $payload['check_out'] ?? null,
                    'actual_check_out' => $payload['actual_check_out'] ?? null,
                    'vehicle_plate'    => $payload['vehicle_plate'] ?? null,
                    'status'           => $payload['status'] ?? 'confirmed',
                    'audit_status'     => $payload['audit_status'] ?? 'new',
                    'is_locked'        => $isLocked,
                    'locked_by'        => $isLocked ? $receptionistUser->id : null,
                    'security_notes'   => $payload['security_notes'] ?? null,
                    'audit_notes'      => $payload['audit_notes'] ?? null,
                ]
            );

            DB::table('reservation_guest')->where('reservation_id', $reservation->id)->delete();

            $occupants = $payload['occupants'] ?? [];
            foreach ($occupants as $occ) {
                DB::table('reservation_guest')->insert([
                    'reservation_id' => $reservation->id,
                    'guest_id'       => (int)$occ['guest_id'],
                    'companion_of_guest_id'   => $occ['companion_of_guest_id'] ?? null,
                    'participant_type'        => $occ['participant_type'] ?? 'companion',
                    'relationship'            => $occ['relationship'] ?? null,
                    'vehicle_plate_at_checkin'=> $payload['vehicle_plate'] ?? null,
                    'registered_by'           => $receptionistUser->id,
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ]);
            }

            return $reservation;
        };

        // نزلاء
        $g1 = $makeGuest([
            'national_id' => '9000000001',
            'first_name'  => 'محمد',
            'father_name' => 'عبدالله',
            'last_name'   => 'الحموي',
            'mother_name' => 'خديجة',
            'phone'       => '0930000001',
        ]);

        $g2 = $makeGuest([
            'national_id' => '9000000002',
            'first_name'  => 'أحمد',
            'father_name' => 'محمود',
            'last_name'   => 'اليوسف',
            'mother_name' => 'مريم',
            'phone'       => '0930000002',
        ]);

        $g3 = $makeGuest([
            'national_id' => '9000000003',
            'first_name'  => 'عمر',
            'father_name' => 'خالد',
            'last_name'   => 'السالم',
            'mother_name' => 'سعاد',
            'phone'       => '0930000003',
        ]);

        $g4 = $makeGuest([
            'national_id' => '9000000004',
            'first_name'  => 'مصطفى',
            'father_name' => 'إبراهيم',
            'last_name'   => 'العلي',
            'mother_name' => 'ليلى',
            'phone'       => '0930000004',
        ]);

        $g5 = $makeGuest([
            'national_id' => '9000000005',
            'first_name'  => 'يوسف',
            'father_name' => 'حسن',
            'last_name'   => 'النجار',
            'mother_name' => 'نعيمة',
            'phone'       => '0930000005',
        ]);

        // (A) دخول اليوم
        $createReservation([
            'room_number' => '101',
            'check_in'    => $t0->copy()->toDateTimeString(),
            'check_out'   => $t0->copy()->addDays(2)->toDateTimeString(),
            'actual_check_out' => null,
            'vehicle_plate' => '741',
            'status' => 'confirmed',
            'audit_status' => 'new',
            'is_locked' => false,
            'occupants' => [
                ['guest_id' => $g2->id, 'participant_type' => 'primary'],
                ['guest_id' => $g3->id, 'participant_type' => 'companion', 'companion_of_guest_id' => $g2->id, 'relationship' => 'مرافق'],
            ],
        ]);

        // (B) لازم يغادروا اليوم
        $createReservation([
            'room_number' => '102',
            'check_in'    => $t0->copy()->subDays(3)->toDateTimeString(),
            'check_out'   => $t0->copy()->toDateTimeString(),
            'actual_check_out' => null,
            'vehicle_plate' => 'XXX',
            'status' => 'confirmed',
            'audit_status' => 'new',
            'is_locked' => false,
            'occupants' => [
                ['guest_id' => $g1->id, 'participant_type' => 'primary'],
            ],
        ]);

        // (C) غادروا اليوم فعلياً
        $createReservation([
            'room_number' => '201',
            'check_in'    => $t0->copy()->subDays(2)->toDateTimeString(),
            'check_out'   => $t0->copy()->toDateTimeString(),
            'actual_check_out' => $t0->copy()->addHours(10)->toDateTimeString(),
            'vehicle_plate' => '555',
            'status' => 'checked_out',
            'audit_status' => 'new',
            'is_locked' => false,
            'occupants' => [
                ['guest_id' => $g4->id, 'participant_type' => 'primary'],
            ],
        ]);

        // (D) إقامة نشطة مقفلة
        $createReservation([
            'room_number' => '202',
            'check_in'    => $t0->copy()->subDay()->toDateTimeString(),
            'check_out'   => $t0->copy()->addDay()->toDateTimeString(),
            'actual_check_out' => null,
            'vehicle_plate' => '999',
            'status' => 'confirmed',
            'audit_status' => 'audited',
            'is_locked' => true,
            'security_notes' => 'سجل مقفل للتجربة',
            'occupants' => [
                ['guest_id' => $g5->id, 'participant_type' => 'primary'],
            ],
        ]);

        // (E) دخول اليوم لكن ملغي
        $createReservation([
            'room_number' => '103',
            'check_in'    => $t0->copy()->addHours(1)->toDateTimeString(),
            'check_out'   => $t0->copy()->addDays(1)->toDateTimeString(),
            'actual_check_out' => null,
            'vehicle_plate' => null,
            'status' => 'cancelled',
            'audit_status' => 'new',
            'is_locked' => false,
            'occupants' => [
                ['guest_id' => $g3->id, 'participant_type' => 'primary'],
            ],
        ]);

        $this->command->info('✅ Database Seeded Successfully.');
        $this->command->info('👤 Receptionist Created: user1@gmail.com / 123456789');
        $this->command->info('👤 HQ Auditor Created: user2@gmail.com / 123456789');
        $this->command->info('👤 HQ Admin Created: user3@gmail.com / 123456789');
        $this->command->info('🏨 Rooms Seeded: ' . count($rooms));
        $this->command->info('🧾 Reservations Seeded: 5');
    }
}