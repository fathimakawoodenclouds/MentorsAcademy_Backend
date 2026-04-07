<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $superAdminRole = Role::where('name', 'super_admin')->first();

        if (! $superAdminRole) {
            Log::error('Super Admin role not found. Please run RoleSeeder first.');
            return;
        }

        $email = env('SUPER_ADMIN_EMAIL', 'super@mentors.com');
        $password = env('SUPER_ADMIN_PASSWORD', 'password');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'System Super Admin',
                'password' => Hash::make($password),
                'role_id' => $superAdminRole->id,
            ]
        );
    }
}
