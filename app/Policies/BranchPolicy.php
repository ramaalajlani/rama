<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if (($user->status ?? '') !== 'active') return false;
        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['hq_supervisor','hq_auditor']);
    }

    public function view(User $user, Branch $branch): bool
    {
        if ($user->hasAnyRole(['hq_supervisor','hq_auditor'])) return true;

        // reception: فقط فرعه
        return $user->hasRole('branch_reception')
            && (int)$user->branch_id === (int)$branch->id;
    }

    public function create(User $user): bool { return false; }
    public function update(User $user, Branch $branch): bool { return false; }
    public function delete(User $user, Branch $branch): bool { return false; }
}