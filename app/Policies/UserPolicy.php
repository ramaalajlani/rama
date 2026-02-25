<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{

    public function viewAny(User $user)
    {
        return $user->hasRole(['hq_admin', 'hq_supervisor', 'branch_manager']);
    }

    public function create(User $user)
    {
        return $user->hasRole(['hq_admin', 'hq_supervisor']);
    }

    public function update(User $user, User $model)
    {

        if ($user->hasRole('hq_admin')) {
            return true;
        }


        return $user->hasRole('branch_manager') && $user->branch_id === $model->branch_id;
    }

    public function delete(User $user, User $model)
    {
        return $user->hasRole('hq_admin');
    }
}