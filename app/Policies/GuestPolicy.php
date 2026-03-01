<?php

namespace App\Policies;

use App\Models\Guest;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Support\Facades\DB;

class GuestPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if (($user->status ?? '') !== 'active') {
            return false;
        }

        if ($user->hasRole('hq_admin')) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['branch_reception', 'hq_auditor', 'hq_security', 'hq_supervisor']);
    }

    public function view(User $user, Guest $guest): bool
    {
        // HQ roles يشوفوا أي نزيل
        if ($user->hasAnyRole(['hq_auditor', 'hq_security', 'hq_supervisor'])) {
            return true;
        }

        // branch_reception: يشوف فقط نزلاء “زاروا” فرعه
        if ($user->hasRole('branch_reception')) {
            return $this->guestVisitedUserBranch($guest->id, (int)$user->branch_id);
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['branch_reception', 'hq_supervisor']);
    }

    public function update(User $user, Guest $guest): bool
    {
        // HQ security فقط يعدّل blacklisted
        if (($guest->status ?? '') === 'blacklisted') {
            return $user->hasRole('hq_security');
        }

        // flagged: security أو supervisor
        if ((bool) $guest->is_flagged) {
            return $user->hasAnyRole(['hq_security', 'hq_supervisor']);
        }

        // audited lock: branch_reception ممنوع
        if (($guest->audit_status ?? '') === 'audited' && $user->hasRole('branch_reception')) {
            return false;
        }

        // supervisor دائماً
        if ($user->hasRole('hq_supervisor')) {
            return true;
        }

        // branch_reception: فقط إذا النزيل زار فرعه
        if ($user->hasRole('branch_reception')) {
            return $this->guestVisitedUserBranch($guest->id, (int)$user->branch_id);
        }

        return false;
    }

    public function audit(User $user, Guest $guest): bool
    {
        return $user->hasAnyRole(['hq_auditor', 'hq_supervisor']);
    }

    public function delete(User $user, Guest $guest): bool
    {
        return false;
    }

    /**
     * Helper: هل النزيل له إقامة ضمن فرع المستخدم؟
     */
    private function guestVisitedUserBranch(int $guestId, int $branchId): bool
    {
        if ($branchId <= 0 || $guestId <= 0) return false;

        return DB::table('reservation_guest as rg')
            ->join('guest_reservations as gr', 'gr.id', '=', 'rg.reservation_id')
            ->where('rg.guest_id', $guestId)
            ->where('gr.branch_id', $branchId)
            ->whereNull('gr.deleted_at')
            ->exists();
    }
}