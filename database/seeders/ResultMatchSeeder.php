<?php

namespace Database\Seeders;

use App\Actions\Results\SaveMatchdayResult;
use App\Enums\MatchdayStatus;
use App\Enums\SeasonStatus;
use App\Models\Matchday;
use App\Models\MatchdayQuestion;
use DomainException;
use Illuminate\Database\Seeder;

/**
 * Wyniki testowe (tylko ręcznie: php artisan db:seed --class=ResultMatchSeeder).
 * Dla każdej kolejki aktywnego sezonu po pierwszym gwizdku, jeszcze bez przeliczenia, po kolei od najniższej:
 * wpisuje zwycięstwo Lecha (1-4 gole, rywal mniej), losuje tak/nie na wszystkie pytania kolejki
 * (żadne nie zostaje bez odpowiedzi) i przelicza kolejkę tak samo jak przycisk w panelu
 * (punkty, mecze, puchar, losowanie Ligi podwórkowej).
 */
class ResultMatchSeeder extends Seeder
{
    public function run(SaveMatchdayResult $results): void
    {
        $matchdays = Matchday::whereHas('season', fn ($q) => $q->where('status', SeasonStatus::Active->value))
            ->where('status', MatchdayStatus::Planned->value)
            ->whereNotNull('kickoff_at')
            ->where('kickoff_at', '<=', now())
            ->orderBy('season_id')
            ->orderBy('number')
            ->get();

        if ($matchdays->isEmpty()) {
            $this->command?->warn('Brak kolejek po pierwszym gwizdku, które czekają na wynik.');

            return;
        }

        foreach ($matchdays as $matchday) {
            $lech = random_int(1, 4);
            $opponent = random_int(0, $lech - 1);

            $correct = MatchdayQuestion::where('matchday_id', $matchday->id)->pluck('id')
                ->mapWithKeys(fn ($id) => [$id => (string) random_int(0, 1)])
                ->all();

            try {
                $stats = $results->handle($matchday, $lech, $opponent, $correct);
            } catch (DomainException $e) {
                // Kolejki idą po kolei: gdy jedna się nie uda, kolejne też by się nie udały.
                $this->command?->error("Kolejka {$matchday->number}: {$e->getMessage()}");

                return;
            }

            $this->command?->info("Kolejka {$matchday->number}: {$lech}:{$opponent}, pytania: ".count($correct).", mecze: {$stats['fixtures']}, podwórkowa: {$stats['swiss']}.");
        }
    }
}
