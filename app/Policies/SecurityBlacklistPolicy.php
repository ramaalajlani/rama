<?php

namespace App\Policies;

use App\Models\User;
use App\Models\SecurityBlacklist;
use Illuminate\Auth\Access\HandlesAuthorization;

class SecurityBlacklistPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if (($user->status ?? '') !== 'active') {
            return false;
        }

        // جوكر الإدارة
        if ($user->hasRole('hq_admin')) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['hq_security', 'hq_auditor', 'hq_supervisor']);
    }

    public function view(User $user, SecurityBlacklist $blacklist): bool
    {
        return $user->hasAnyRole(['hq_security', 'hq_auditor', 'hq_supervisor']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('hq_security');
    }

    public function update(User $user, SecurityBlacklist $blacklist): bool
    {
        return $user->hasRole('hq_security');
    }

    /**
     * صلاحية الفحص الأمني (Match Checking)
     * ✅ branch_reception مسموح يعمل check لكن بدون ما تعرض له التفاصيل (وهذا أنت عاملُه بالخدمات).
     */
    public function check(User $user): bool
    {
        return $user->hasAnyRole(['hq_security', 'hq_auditor', 'hq_supervisor', 'branch_reception']);
    }

    /**
     * رادار التنبيهات + المعالجة
     */
    public function audit(User $user): bool
    {
        return $user->hasAnyRole(['hq_security', 'hq_auditor', 'hq_supervisor']);
    }

    public function delete(User $user, SecurityBlacklist $blacklist): bool
    {
        // أنصح تمنعه نهائياً
        return false;
    }
}