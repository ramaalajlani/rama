<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Room;
use Illuminate\Auth\Access\HandlesAuthorization;

class RoomPolicy
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
        return $user->hasAnyRole([
            'branch_reception',
            'hq_supervisor',
            'hq_auditor',
            'hq_security'
        ]);
    }

    public function view(User $user, Room $room): bool
    {
        if ($user->hasAnyRole(['hq_supervisor', 'hq_auditor', 'hq_security'])) {
            return true;
        }

        return $user->hasRole('branch_reception')
            && (int)$user->branch_id === (int)$room->branch_id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('hq_supervisor');
    }

    public function update(User $user, Room $room): bool
    {
        if ($user->hasRole('hq_supervisor')) {
            return true;
        }

        return $user->hasRole('branch_reception')
            && (int)$user->branch_id === (int)$room->branch_id;
    }

    public function updateStatus(User $user, Room $room): bool
    {
        if ($user->hasAnyRole(['hq_security', 'hq_supervisor'])) {
            return true;
        }

        return $user->hasRole('branch_reception')
            && (int)$user->branch_id === (int)$room->branch_id;
    }

    public function delete(User $user, Room $room): bool
    {
        return false;
    }
}