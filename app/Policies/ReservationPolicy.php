<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ReservationPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        // أي مستخدم غير active ممنوع كل شيء
        if (($user->status ?? '') !== 'active') {
            return false;
        }

        // إذا عندك hq_admin وبدك يكون جوكر للنظام:
        // if ($user->hasRole('hq_admin')) return true;

        return null;
    }

    public function viewAny(User $user): bool
    {
        // ✅ أضفت hq_security لأنه منطقي يشوف الإقامات عند وجود تنبيه
        return $user->hasAnyRole(['branch_reception', 'hq_supervisor', 'hq_auditor', 'hq_security']);
    }

    public function view(User $user, Reservation $reservation): bool
    {
        if ($user->hasAnyRole(['hq_supervisor', 'hq_auditor', 'hq_security'])) {
            return true;
        }

        return $user->hasRole('branch_reception')
            && (int)$user->branch_id === (int)$reservation->branch_id;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['branch_reception', 'hq_supervisor']);
    }

    public function update(User $user, Reservation $reservation): bool
    {
        // بعد القفل/التدقيق: فقط supervisor
        if ((bool)$reservation->is_locked || ($reservation->audit_status ?? '') === 'audited') {
            return $user->hasRole('hq_supervisor');
        }

        // قبل القفل: supervisor دائماً
        if ($user->hasRole('hq_supervisor')) {
            return true;
        }

        // الاستقبال ضمن فرعه
        return $user->hasRole('branch_reception')
            && (int)$user->branch_id === (int)$reservation->branch_id;
    }

    public function audit(User $user, Reservation $reservation): bool
    {
        // تدقيق/قفل: supervisor فقط
        return $user->hasRole('hq_supervisor');
    }

    public function checkOut(User $user, Reservation $reservation): bool
    {
        // supervisor دائماً
        if ($user->hasRole('hq_supervisor')) {
            return true;
        }

        // الاستقبال ضمن فرعه + إقامة غير منتهية
        return $user->hasRole('branch_reception')
            && (int)$user->branch_id === (int)$reservation->branch_id
            && is_null($reservation->actual_check_out);
    }

    public function viewDocuments(User $user, Reservation $reservation): bool
    {
        // الوثائق: supervisor/auditor/security
        if ($user->hasAnyRole(['hq_supervisor', 'hq_auditor', 'hq_security'])) {
            return true;
        }

        // الاستقبال: فقط إذا ضمن فرعه والسجل غير مقفل
        return $user->hasRole('branch_reception')
            && (int)$user->branch_id === (int)$reservation->branch_id
            && !(bool)$reservation->is_locked;
    }
}