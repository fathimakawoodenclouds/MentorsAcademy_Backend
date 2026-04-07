<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'super_admin' => 'Super Admin',
            'admin' => 'Admin',
            'office_staff' => 'Office Staff',
            'unit_head' => 'Unit Head',
            'coordinator' => 'Coordinator',
            'school' => 'School Representative',
            'activity_head' => 'Activity Head',
            'coach' => 'Coach',
            'sales_executive' => 'Sales Executive'
        ];

        foreach ($roles as $name => $displayName) {
            Role::updateOrCreate(
                ['name' => $name],
                ['display_name' => $displayName]
            );
        }
    }
}
