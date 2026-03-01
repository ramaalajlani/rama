<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class UserService
{
    /**
     * قائمة المستخدمين للمدير مع فلاتر
     *
     * @param array{
     *   branch_id?: int|null,
     *   status?: string|null,
     *   q?: string|null,
     *   per_page?: int|null
     * } $filters
     */
    public function getUsersForManager(array $filters = [])
    {
        $admin = Auth::user();

        $perPage = (int)($filters['per_page'] ?? 20);
        if ($perPage < 1) $perPage = 20;
        if ($perPage > 100) $perPage = 100;

        $q = User::query()
            ->select(['id', 'name', 'email', 'branch_id', 'status', 'created_at'])
            ->with(['branch:id,name'])
            ->latest('id');

        // ✅ اعتبر كل أدوار HQ ضمن رؤية الكل
        $isHQ = $admin && $admin->hasAnyRole(['hq_admin', 'hq_supervisor', 'hq_security', 'hq_auditor']);

        if (!$isHQ) {
            $q->where('branch_id', (int)($admin->branch_id ?? 0));
        } else {
            // HQ: يسمح بفلترة branch_id إذا > 0
            if (array_key_exists('branch_id', $filters) && (int)$filters['branch_id'] > 0) {
                $q->where('branch_id', (int)$filters['branch_id']);
            }
        }

        if (!empty($filters['status'])) {
            $q->where('status', (string)$filters['status']);
        }

        if (!empty($filters['q'])) {
            $term = trim((string)$filters['q']);
            $q->where(function ($w) use ($term) {
                $w->where('name', 'like', "%{$term}%")
                  ->orWhere('email', 'like', "%{$term}%");
            });
        }

        return $q->paginate($perPage);
    }

    /**
     * إنشاء مستخدم + تعيين Role (Atomic)
     * يرجّع user + roles
     */
    public function createUser(array $data): array
    {
        return DB::transaction(function () use ($data) {

            /** @var User $user */
            $user = User::create([
                'name'      => $data['name'],
                'email'     => $data['email'],
                'password'  => Hash::make($data['password']),
                'branch_id' => $data['branch_id'] ?? null,
                'status'    => $data['status'] ?? 'active',
            ]);

            if (!empty($data['role'])) {
                $user->assignRole($data['role']);

                // ✅ امسح كاش Spatie
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }

            // ✅ invalidate كاش /auth/me للمستخدم (حتى لو بدون role)
            Cache::increment("auth:permver:user:{$user->id}");

            return [
                'user'  => $user->loadMissing(['branch:id,name']),
                'roles' => $user->getRoleNames()->values(),
            ];
        });
    }
}