<?php

namespace Database\Seeders;

use App\Models\OfficeStaff;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class OfficeStaffSeeder extends Seeder
{
    /**
     * Demo office staff for local login (same pattern as SuperAdminSeeder).
     * Does not remove other users or attendance data.
     */
    public function run(): void
    {
        $role = Role::where('name', 'office_staff')->first();
        if (! $role) {
            Log::error('Office staff role not found. Run RoleSeeder first.');

            return;
        }

        $email = env('OFFICE_STAFF_EMAIL', 'staff@mentors.com');
        $password = env('OFFICE_STAFF_PASSWORD', 'password');

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Demo Office Staff',
                'password' => Hash::make($password),
                'role_id' => $role->id,
            ]
        );

        $user->staffProfile()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'phone' => null,
                'age' => 30,
                'address' => 'Demo address',
                'state' => 'Kerala',
                'city' => 'Kochi',
                'pincode' => '682001',
                'wage_type' => 'Monthly',
                'salary' => 25000,
                'status' => 'active',
            ]
        );

        $existingOffice = OfficeStaff::withTrashed()->where('user_id', $user->id)->first();
        if ($existingOffice) {
            if ($existingOffice->trashed()) {
                $existingOffice->restore();
            }
        } else {
            OfficeStaff::create(['user_id' => $user->id]);
        }
    }
}
