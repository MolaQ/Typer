<?php

namespace App\Actions\Seasons;

use App\Models\Bot;
use App\Models\Season;
use App\Models\SeasonTeam;
use Illuminate\Support\Facades\DB;

/**
 * Dodaje do listy sezonu WSZYSTKIE boty z puli, których jeszcze na niej nie ma.
 *
 * Kolejność: najpierw ewentualne luki w numeracji (1..max), potem koniec listy.
 * Boty idą według sort_order, więc wynik jest powtarzalny. Gracze zostają tam,
 * gdzie są, a pierwsze 512 miejsc to uczestnicy Pucharu Polski.
 * Używane przy zatwierdzaniu sezonu i przyciskiem "Uzupełnij botami".
 *
 * @return int ile botów dodano
 */
class FillTeamListWithBots
{
    public function handle(Season $season): int
    {
        return DB::transaction(function () use ($season): int {
            $used = SeasonTeam::where('season_id', $season->id)->whereNotNull('bot_id')->select('bot_id');

            $bots = Bot::query()->whereNotIn('id', $used)->orderBy('sort_order')->get();

            if ($bots->isEmpty()) {
                return 0;
            }

            $taken = SeasonTeam::where('season_id', $season->id)->pluck('position')->all();
            $takenSet = array_flip($taken);
            $max = $taken === [] ? 0 : max($taken);

            // Wolne numery w środku listy.
            $gaps = [];
            for ($position = 1; $position <= $max; $position++) {
                if (! isset($takenSet[$position])) {
                    $gaps[] = $position;
                }
            }

            $next = $max + 1;
            $now = now();
            $rows = [];

            foreach ($bots as $index => $bot) {
                $rows[] = [
                    'season_id' => $season->id,
                    'position' => $gaps[$index] ?? $next++,
                    'user_id' => null,
                    'bot_id' => $bot->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                SeasonTeam::insert($chunk);
            }

            return count($rows);
        });
    }
}
