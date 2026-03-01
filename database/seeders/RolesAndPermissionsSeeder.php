<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // تنظيف الكاش
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = 'api';

        // 1. إنشاء الصلاحيات
        $permissions = [
            'view_blacklist', 'manage_blacklist', 
            'view_notifications', 'mark_notifications_read',
            'view_audit_logs'
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => $guard]);
        }

        // 2. إنشاء الأدوار
        $admin = Role::firstOrCreate(['name' => 'hq_admin', 'guard_name' => $guard]);
        $admin->syncPermissions(Permission::where('guard_name', $guard)->get());

        $auditor = Role::firstOrCreate(['name' => 'hq_auditor', 'guard_name' => $guard]);
        $auditor->syncPermissions(['view_blacklist', 'view_notifications', 'mark_notifications_read', 'view_audit_logs']);

        $security = Role::firstOrCreate(['name' => 'hq_security', 'guard_name' => $guard]);
        $security->syncPermissions(['view_blacklist', 'manage_blacklist', 'view_notifications']);

        // 3. ربط المستخدمين (تأكد من وجود المستخدمين في قاعدة البيانات أولاً)
        $usersMap = [
            'user3@gmail.com' => 'hq_admin',
            'user2@gmail.com' => 'hq_auditor',
        ];

        foreach ($usersMap as $userEmail => $roleName) {
            $user = User::where('email', $userEmail)->first();
            if ($user) {
                // مسح أي أدوار قديمة بـ Guard مختلف
                DB::table('model_has_roles')->where('model_id', $user->id)->delete();
                $user->assignRole($roleName);
            }
        }
    }
}