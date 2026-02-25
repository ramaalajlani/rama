<?php

namespace Database\Seeders;

use App\Models\{User, Branch, Room, Guest, SecurityBlacklist};
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\{Role, Permission};
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * تشغيل بذور قاعدة البيانات - الإصدار الأمني المتكامل 2026
     */
    public function run(): void
    {
        // 1. تنظيف كاش الصلاحيات لضمان عدم حدوث تضارب
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = 'api';

        // 2. مصفوفة الصلاحيات (أمنية، رقابية، وتشغيلية)
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

        // 3. إعداد الأدوار
        // مدير HQ
        $adminRole = Role::firstOrCreate(['name' => 'hq_admin', 'guard_name' => $guard]);
        $adminRole->syncPermissions(Permission::where('guard_name', $guard)->get());

        // موظف استقبال الفرع
        $receptionistRole = Role::firstOrCreate(['name' => 'branch_reception', 'guard_name' => $guard]);
        $receptionistRole->syncPermissions(['create_guests', 'view_guests', 'mark_notifications_read']);

        // 4. إنشاء الفرع التجريبي
        $branch1 = Branch::updateOrCreate(
            ['name' => 'Four Seasons - Damascus'], 
            ['address' => 'Damascus - Abu Rummaneh', 'status' => 'active']
        );

        // 5. إنشاء المستخدمين (الأدمن وموظف الاستقبال)
        // المستخدم المدير
        $hqMaster = User::updateOrCreate(
            ['email' => 'admin@hotel.com'],
            ['name' => 'System Master Admin', 'password' => Hash::make('Admin@2026'), 'status' => 'active']
        );
        $hqMaster->syncRoles([$adminRole]);

        // موظف الاستقبال (الذي طلبتهِ)
        $receptionistUser = User::updateOrCreate(
            ['email' => 'ahmed@hotel.com'],
            [
                'name' => 'Ahmed Receptionist', 
                'password' => Hash::make('Ahmed@123'), 
                'branch_id' => $branch1->id, 
                'status' => 'active'
            ]
        );
        $receptionistUser->syncRoles([$receptionistRole]);

        // 6. محرك الهاش الأمني (توليد البصمات الرقمية)
        $salt = config('app.key');
        $generateHash = function($val) use ($salt) {
            if (!$val) return null;
            return hash('sha256', Str::lower(str_replace(' ', '', trim($val))) . $salt);
        };

        // 7. بيانات القائمة السوداء (مع هاشات الأب والأم المنفصلة)
        SecurityBlacklist::updateOrCreate(
            ['identity_hash' => $generateHash('9876543210')],
            [
                'full_name_hash'    => $generateHash('Samer' . 'Ali' . 'Hassan'),
                'father_name_hash'  => $generateHash('Ali'),   // هاش الأب المنفصل
                'mother_name_hash'  => $generateHash('Faten'), // هاش الأم المنفصل
                'triple_check_hash' => $generateHash('Samer' . 'Ali' . 'Faten'), // الهاش الثلاثي
                'full_hash'         => $generateHash('Samer' . 'Ali' . 'Hassan' . 'Faten'),
                'risk_level'        => 'CRITICAL',
                'reason'            => 'مطلوب بموجب تعميم أمني رقم 405',
                'instructions'      => 'قفل ملف الحجز فوراً وإبلاغ الأمن المركزي بهدوء.',
                'is_active'         => true,
                'created_by'        => $hqMaster->id
            ]
        );

        // 8. غرف اختبارية (بدون حقل price_per_night لتجنب الخطأ)
        Room::updateOrCreate(
            ['room_number' => '101', 'branch_id' => $branch1->id],
            [
                'type' => 'suite', 
                'status' => 'available', 
                'floor_number' => 1
            ]
        );

        // 9. نزيل اختباري (للتأكد من عمل النظام)
        $testId = '1234567890';
        Guest::updateOrCreate(
            ['national_id' => $testId],
            [
                'first_name'         => 'John',
                'father_name'        => 'Edward',
                'last_name'          => 'Doe',
                'mother_name'        => 'Jane',
                'id_type'            => 'national_id',
                'nationality'        => 'Syrian',
                'phone'              => '0930111222',
                'national_id_hash'   => $generateHash($testId),
                'full_security_hash' => $generateHash('John' . 'Edward' . 'Doe' . 'Jane'),
                'is_flagged'         => false,
                'status'             => 'active',
                'audit_status'       => 'new'
            ]
        );

        $this->command->info('✅ Database Seeded Successfully.');
        $this->command->info('👤 Receptionist Created: ahmed@hotel.com / Ahmed@123');
    }
}