<?php

namespace App\Console\Commands;

use App\Support\GameStars;
use Illuminate\Console\Command;

/**
 * Przelicza od zera tabelę Gwiazd gry (star_stats) ze wszystkich rozliczonych kolejek. Potrzebne raz po migracji
 * (16.10.2026), dalej przeliczenie robi ScoreMatchday po każdej kolejce.
 */
class RebuildStars extends Command
{
    protected $signature = 'stars:rebuild';

    protected $description = 'Rebuild the Game stars table (star_stats) from all settled matchdays';

    public function handle(): int
    {
        $started = microtime(true);
        $seasons = GameStars::rebuildEverything();
        $this->info(sprintf('Game stars rebuilt: %d seasons, %.1f s.', $seasons, microtime(true) - $started));

        return self::SUCCESS;
    }
}
