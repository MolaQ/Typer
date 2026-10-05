<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Czyścimy pamięć podręczną uprawnień Spatie, żeby zmiany były widoczne od razu.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Każde uprawnienie z enuma trafia do bazy. Istniejące się nie dublują.
        foreach (Permission::values() as $name) {
            PermissionModel::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Rola Admin dostaje wszystkie uprawnienia.
        $admin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $admin->syncPermissions(Permission::values());

        // Rola bez uprawnień dla zwykłych kont (nadajesz ją w panelu użytkownikom).
        Role::firstOrCreate(['name' => 'User', 'guard_name' => 'web']);

        // Opcjonalnie: konto administratora z adresu zapisanego w .env (ADMIN_EMAIL=...).
        $email = env('ADMIN_EMAIL');

        if ($email) {
            User::where('email', $email)->first()?->assignRole($admin);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}