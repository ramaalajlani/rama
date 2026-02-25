<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class UserService
{
 
    public function getUsersForManager()
    {
        $admin = Auth::user();

        if ($admin->hasRole(['hq_admin', 'hq_supervisor'])) {
            return User::with('branch')->latest()->get();
        }

        return User::where('branch_id', $admin->branch_id)->latest()->get();
    }

 
    public function createUser(array $data)
    {
        $user = User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'branch_id' => $data['branch_id'] ?? null,
            'status'    => 'active'
        ]);

  
        if (isset($data['role'])) {
            $user->assignRole($data['role']);
        }

        return $user;
    }
}