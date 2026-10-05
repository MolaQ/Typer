<?php

use App\Enums\Permission;
use Illuminate\Support\Facades\Route;

// Strona publiczna
Route::livewire('/', 'pages::home.welcome')->name('home');
Route::livewire('/permission', 'pages::home.permissiontest')->name('permission'); // tymczasowy test

// Panel: wejście tylko z uprawnieniem dashboard-access (Admin ma je zawsze)
Route::middleware(['auth', 'verified', 'permission:' . Permission::DashboardAccess->value])->group(function () {

    Route::livewire('dashboard', 'pages::dashboard.stats')
        ->name('dashboard');

    // Zarządzanie rolami i użytkownikami: nadal tylko Admin
    Route::livewire('dashboard/roles', 'pages::dashboard.roles')
        ->middleware('role:Admin')
        ->name('dashboard.roles');

    Route::livewire('dashboard/users', 'pages::dashboard.users')
        ->middleware('role:Admin')
        ->name('dashboard.users');

    Route::livewire('dashboard/team-requests', 'pages::dashboard.team-requests')
        ->middleware('permission:' . Permission::TeamChangeName->value)
        ->name('dashboard.team-requests');

    Route::livewire('dashboard/logs', 'pages::dashboard.logs')
        ->middleware('permission:' . Permission::LogView->value)
        ->name('dashboard.logs');
});

require __DIR__ . '/settings.php';