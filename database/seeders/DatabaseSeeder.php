<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * php artisan db:seed: wszystkie seedery poza TestTipSeeder (typy testowe uruchamiasz ręcznie:
     * php artisan db:seed --class=TestTipSeeder). Każdy seeder można bezpiecznie powtórzyć, niczego nie dubluje.
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
        ]);
    }
}
