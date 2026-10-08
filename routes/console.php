<?php

use Illuminate\Foundation\Inspiring;
use App\Support\Premium;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Premium (etap 15): po dacie wygaśnięcia rola Premium znika. Harmonogram wymaga uruchamiania
// „php artisan schedule:run” co minutę (na Windowsie: Harmonogram zadań).
Artisan::command('premium:expire', function () {
    $this->info('Premium expired: ' . Premium::expire());
})->purpose('Remove the Premium role after its expiry date');

Schedule::command('premium:expire')->hourly();
