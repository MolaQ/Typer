<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tworzy wszystkie uprawnienia z enuma Permission i role z enuma RoleName.
 * Można uruchamiać wielokrotnie: istniejące wpisy zostają, brakujące są dopisywane.
 *
 *   php artisan db:seed --class=RolesAndPermissionsSeeder
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Spatie trzyma uprawnienia w cache, więc czyścimy go przed zmianami.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permission::values() as $name) {
            PermissionModel::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (RoleName::values() as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Admin ma wszystkie uprawnienia (poza tym Gate::before i tak go przepuszcza).
        // Pozostałe role dostają uprawnienia ręcznie w panelu "Role i uprawnienia",
        // seeder ich nie nadpisuje. User, Premium i Banned startują bez uprawnień.
        Role::findByName(RoleName::Admin->value, 'web')->syncPermissions(PermissionModel::all());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
