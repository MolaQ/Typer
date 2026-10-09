<?php

namespace Database\Seeders;

use App\Models\TrophyIcon;
use App\Support\HallOfFame;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Domyślne ikony trofeów Hall of Fame (etap 14). Pliki PNG leżą w database/seeders/trophies (nazwa = klucz trofeum),
 * seeder kopiuje je na dysk public (katalog trophies). Ikon wgranych w panelu nie nadpisuje: żeby wrócić do
 * domyślnej, usuń ikonę w panelu i uruchom seeder ponownie.
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

            if (TrophyIcon::where('key', $key)->exists()) {
                continue;
            }

            $path = 'trophies/default-'.$key.'.png';
            $disk->put($path, file_get_contents($source));

            TrophyIcon::create(['key' => $key, 'path' => $path]);
            $added++;
        }

        $this->command?->info("Trophy icons: {$added}");
    }
}
