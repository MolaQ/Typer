<?php

namespace Database\Seeders;

use App\Models\TrophyIcon;
use App\Support\HallOfFame;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Domyślne ikony trofeów Hall of Fame (etap 14). Pliki PNG leżą w database/seeders/trophies (nazwa = klucz trofeum),
 * seeder kopiuje je na dysk public (katalog trophies). Domyślne ikony (plik trophies/default-*.png) odświeża
 * przy każdym uruchomieniu, więc nowe wersje trafiają na stronę. Ikon wgranych w panelu nie nadpisuje: żeby wrócić
 * do domyślnej, usuń ikonę w panelu i uruchom seeder ponownie.
 *
 *   php artisan db:seed --class=TrophyIconsSeeder
 */
class TrophyIconsSeeder extends Seeder
{
    public function run(): void
    {
        $disk = Storage::disk('public');
        $added = 0;

        foreach (array_keys(HallOfFame::trophies()) as $key) {
            $source = database_path('seeders/trophies/'.$key.'.png');

            if (! is_file($source)) {
                continue;
            }

            $path = 'trophies/default-'.$key.'.png';
            $icon = TrophyIcon::where('key', $key)->first();

            // Ikona wgrana w panelu ma inną ścieżkę: zostawiamy ją.
            if ($icon && $icon->path !== $path) {
                continue;
            }

            $disk->put($path, file_get_contents($source));
            $icon ?? TrophyIcon::create(['key' => $key, 'path' => $path]);
            $added++;
        }

        $this->command?->info("Trophy icons: {$added}");
    }
}
