<?php

use App\Enums\Permission;
use App\Models\User;
use Spatie\Permission\Models\Permission as PermissionModel;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('users without dashboard access are sent to the home page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Brak uprawnienia dashboard-access: obsługa UnauthorizedException w bootstrap/app.php przekierowuje na stronę główną.
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('home'));
});

test('users with dashboard access can visit the dashboard', function () {
    PermissionModel::findOrCreate(Permission::DashboardAccess->value, 'web');

    $user = User::factory()->create();
    $user->givePermissionTo(Permission::DashboardAccess->value);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});
