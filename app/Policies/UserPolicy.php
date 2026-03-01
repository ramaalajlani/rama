<?php
// app/Policies/UserPolicy.php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if (($user->status ?? '') !== 'active') return false;
        if ($user->hasRole('hq_admin')) return true;
        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasRole('hq_supervisor');
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasRole('hq_supervisor');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('hq_supervisor');
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasRole('hq_supervisor');
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }
}