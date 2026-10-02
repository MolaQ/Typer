<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::home.welcome')->name('home');
Route::livewire('/permission', 'pages::home.permissiontest')->name('permission');

Route::middleware(['auth', 'verified'])->group(function () {

    // Panel administracyjny: strona główna ze statystykami
    Route::livewire('dashboard', 'pages::dashboard.stats')
        ->name('dashboard');

    // Zarządzanie rolami: tylko dla roli Admin
    Route::livewire('dashboard/roles', 'pages::dashboard.roles')
        ->middleware('role:Admin')
        ->name('dashboard.roles');

        Route::livewire('dashboard/users', 'pages::dashboard.users')
    ->middleware('role:Admin')
    ->name('dashboard.users');
});

require __DIR__.'/settings.php';
