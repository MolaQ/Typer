<?php

use App\Enums\Permission;
use App\Http\Controllers\Przelewy24Controller;
use Illuminate\Support\Facades\Route;

// Strona publiczna
Route::livewire('/', 'pages::home.welcome')->name('home');
Route::livewire('/permission', 'pages::home.permissiontest')->name('permission'); // tymczasowy test

// Strefa gracza na stronie głównej (bez panelu admina): typowanie i wyniki.
// Typowanie z przyjaznym adresem: /tips/kolejka-3/statystyki (obie części opcjonalne).
Route::livewire('tips/{matchday_slug?}/{tab_slug?}', 'pages::tips')
    ->where(['matchday_slug' => 'kolejka-[0-9]+', 'tab_slug' => '[a-z]+'])
    ->middleware(['auth', 'verified'])
    ->name('tips');
// Wyniki z przyjaznym adresem: /results/sezon-2/ekstraklasa/kolejka-9 (wszystkie części opcjonalne).
Route::livewire('results/{season_slug?}/{competition_slug?}/{round_slug?}', 'pages::results')
    ->where(['season_slug' => 'sezon-[0-9]+', 'competition_slug' => '[a-z0-9-]+', 'round_slug' => 'kolejka-[0-9]+'])
    ->name('results');
Route::livewire('hall-of-fame', 'pages::hall-of-fame')->name('hall-of-fame');
Route::livewire('teams/{user}', 'pages::team-profile')->name('team.show');

// Premium i wsparcie (etap 15): cennik i płatność online, powiadomienia od Przelewy24 (bez CSRF, patrz bootstrap/app.php).
Route::livewire('support', 'pages::support')->name('support');
Route::livewire('rules', 'pages::rules')->name('rules');
Route::livewire('faq', 'pages::faq')->name('faq');
Route::livewire('system', 'pages::system-feed')->name('system');
Route::livewire('news/{news}', 'pages::news-show')->name('news.show');
Route::post('payments/przelewy24/status', [Przelewy24Controller::class, 'status'])->name('przelewy24.status');

// Panel: wejście tylko z uprawnieniem dashboard-access (Admin ma je zawsze)
Route::middleware(['auth', 'verified', 'permission:'.Permission::DashboardAccess->value])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard.stats')
        ->name('dashboard');
    // Zarządzanie rozgrywkami
    Route::livewire('dashboard/seasons', 'pages::dashboard.seasons')
        ->middleware('permission:'.Permission::SeasonList->value)
        ->name('dashboard.seasons');
    Route::livewire('dashboard/matchdays', 'pages::dashboard.matchdays')
        ->middleware('permission:'.Permission::SeasonList->value)
        ->name('dashboard.matchdays');
    Route::livewire('dashboard/season-teams', 'pages::dashboard.season-teams')
        ->middleware('permission:'.Permission::SeasonList->value)
        ->name('dashboard.season-teams');
    Route::livewire('dashboard/bots', 'pages::dashboard.bots')
        ->middleware('permission:'.Permission::SeasonList->value)
        ->name('dashboard.bots');
    Route::livewire('dashboard/fixtures', 'pages::dashboard.fixtures')
        ->middleware('permission:season-list')
        ->name('dashboard.fixtures');
    Route::livewire('dashboard/competitions', 'pages::dashboard.competitions')
        ->middleware('permission:season-list')
        ->name('dashboard.competitions');
    Route::livewire('dashboard/checklist', 'pages::dashboard.checklist')
        ->middleware('permission:season-list')
        ->name('dashboard.checklist');
    Route::livewire('dashboard/questions', 'pages::dashboard.questions')
        ->middleware('permission:season-list')
        ->name('dashboard.questions');
    Route::livewire('dashboard/question-proposals', 'pages::dashboard.question-proposals')
        ->middleware('permission:season-list')
        ->name('dashboard.question-proposals');
    Route::livewire('dashboard/matchday-questions', 'pages::dashboard.matchday-questions')
        ->middleware('permission:season-list')
        ->name('dashboard.matchday-questions');
    Route::livewire('dashboard/tips', 'pages::dashboard.tips-overview')
        ->middleware('permission:season-list')
        ->name('dashboard.tips');
    Route::livewire('dashboard/results', 'pages::dashboard.results')
        ->middleware('permission:season-list')
        ->name('dashboard.results');
    Route::livewire('dashboard/sponsors', 'pages::dashboard.sponsors')
        ->middleware('permission:season-list')
        ->name('dashboard.sponsors');
    Route::livewire('dashboard/hall-of-fame', 'pages::dashboard.hall-of-fame')
        ->middleware('permission:season-list')
        ->name('dashboard.hall-of-fame');
    Route::livewire('dashboard/news', 'pages::dashboard.news')
        ->middleware('permission:'.Permission::NewsCreate->value)
        ->name('dashboard.news');
    // Zarządzanie rolami i użytkownikami: nadal tylko Admin
    Route::livewire('dashboard/roles', 'pages::dashboard.roles')
        ->middleware('role:Admin')
        ->name('dashboard.roles');

    Route::livewire('dashboard/users', 'pages::dashboard.users')
        ->middleware('role:Admin')
        ->name('dashboard.users');

    Route::livewire('dashboard/payments', 'pages::dashboard.payments')
        ->middleware('role:Admin')
        ->name('dashboard.payments');

    Route::livewire('dashboard/team-requests', 'pages::dashboard.team-requests')
        ->middleware('permission:'.Permission::TeamChangeName->value)
        ->name('dashboard.team-requests');

    Route::livewire('dashboard/logs', 'pages::dashboard.logs')
        ->middleware('permission:'.Permission::LogView->value)
        ->name('dashboard.logs');
});

require __DIR__.'/settings.php';
