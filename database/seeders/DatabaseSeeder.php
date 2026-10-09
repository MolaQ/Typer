<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * php artisan db:seed (albo migrate:fresh --seed): wszystkie seedery poza TestTipSeeder i ResultMatchSeeder
     * (uruchamiasz je ręcznie: php artisan db:seed --class=TestTipSeeder). Każdy seeder można bezpiecznie powtórzyć.
     * Kolejność ma znaczenie: DemoSeasonSeeder potrzebuje botów, pytań i graczy, a NewsSeeder graczy do ocen.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CreateAdminUserSeeder::class,
            BotsSeeder::class,
            QuestionsSeeder::class,
            TrophyIconsSeeder::class,
            PlayersSeeder::class,
                //DemoSeasonSeeder::class, // sezon testowy: kolejka 1 za 10 minut, rozgrywki i terminarze
            NewsSeeder::class, // 25 newsów do strony głównej
        ]);
    }
}
