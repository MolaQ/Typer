<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class CreateAdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Create admin user
        $user = User::firstOrCreate(
            ['email' => 'marcin.molak@gmail.com'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('password'),
                'team_name' => "Kolejorz Kaczory",
                'team_short_name' => "Kolejorz",
                'team_abbr' => "KOLKAC",
            ]
        );

        // Admin nie musi potwierdzać adresu kodem.
        if (!$user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        // Create admin role
        $role = Role::firstOrCreate(['name' => 'Admin']);

        // Get all permissions
        $permissions = Permission::pluck('id', 'id')->all();

        // Sync all permissions to admin role
        $role->syncPermissions($permissions);

        // Assign admin role to user
        $user->assignRole([$role->id]);
    }
}
