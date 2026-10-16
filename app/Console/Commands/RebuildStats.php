<?php

namespace App\Console\Commands;

use App\Support\GameStars;
use App\Support\PageCache;
use App\Support\SeasonArchive;
use Illuminate\Console\Command;

/**
 * Przelicza od zera gotowe dane statystyk: tabelę Gwiazd gry (star_stats) ze wszystkich rozliczonych kolejek
 * i czyści archiwum zakończonych sezonów (season_archives, policzy się samo przy pierwszym wejściu).
 * Potrzebne raz po migracji (16.10.2026) i po ręcznych zmianach w bazie; dalej wszystko odświeża ScoreMatchday i FinishSeason.
 */
class RebuildStats extends Command
{
    protected $signature = 'stats:rebuild';

    /** @var list<string> */
    protected $aliases = ['stars:rebuild'];

    protected $description = 'Rebuild the ready-made statistics (Game stars, finished seasons archive)';

    public function handle(): int
    {
        $started = microtime(true);
        SeasonArchive::forget();
        $seasons = GameStars::rebuildEverything();
        PageCache::flush();
        $this->info(sprintf('Statistics rebuilt: %d seasons, %.1f s.', $seasons, microtime(true) - $started));

        return self::SUCCESS;
    }
}
