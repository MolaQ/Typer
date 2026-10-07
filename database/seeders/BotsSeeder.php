<?php

namespace Database\Seeders;

use App\Models\Bot;
use App\Support\BotNames;
use Illuminate\Database\Seeder;

/**
 * Tworzy pulę 512 botów z listy App\Support\BotNames.
 *
 * Bezpieczny do wielokrotnego uruchamiania:
 *  - tworzy tylko brakujące sloty,
 *  - nie nadpisuje nazw zmienionych w panelu admina.
 *
 * Uruchomienie: php artisan db:seed --class=BotsSeeder
 */
class BotsSeeder extends Seeder
{
    public function run(): void
    {
        $created = 0;

        foreach (BotNames::all() as $index => $name) {
            $slot = $index + 1;

            if (Bot::where('sort_order', $slot)->exists()) {
                continue;
            }

            // Jeśli admin nadał komuś tę nazwę, dopisujemy numer slotu (nazwy są unikalne).
            if (Bot::where('name', $name)->exists()) {
                $name = mb_substr($name, 0, 35) . ' ' . $slot;
            }

            Bot::create(['sort_order' => $slot, 'name' => $name]);
            $created++;
        }

        $this->command?->info("Boty: dodano {$created}, razem " . Bot::count() . '.');
    }
}