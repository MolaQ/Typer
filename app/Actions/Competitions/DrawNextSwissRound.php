<?php

namespace App\Actions\Competitions;

use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Support\Standings;
use DomainException;

/**
 * Automatyczne losowanie Ligi podwórkowej po przeliczeniu kolejki: runda N+1 według klasyfikacji po rundzie N.
 * Jeśli runda N+1 była już rozlosowana, ale nie ma jeszcze wyników (np. poprawka wyniku kolejki N),
 * losujemy ją od nowa, żeby pary odpowiadały aktualnej klasyfikacji.
 * Gdy losowanie się nie uda (np. brak rundy N), nic nie przerywamy: brak par pokaże się w powiadomieniach admina.
 */
class DrawNextSwissRound
{
    public function __construct(private DrawSwissRound $draw) {}

    /** @return int liczba utworzonych meczów */
    public function afterMatchday(Matchday $matchday): int
    {
        $next = $matchday->number + 1;

        if ($next > DrawSwissRound::ROUNDS) {
            return 0;
        }

        $created = 0;

        foreach (Competition::where('season_id', $matchday->season_id)->where('type', CompetitionType::Swiss->value)->get() as $competition) {
            $fixtures = Fixture::where('competition_id', $competition->id);

            if (! (clone $fixtures)->where('round', $matchday->number)->exists()) {
                continue;
            }

            $nextRound = (clone $fixtures)->where('round', $next);

            if ((clone $nextRound)->whereNotNull('home_goals')->exists()) {
                continue;
            }

            $nextRound->delete();

            try {
                $created += $this->draw->handle($competition, $next, Standings::rankedEntryIds($competition));
            } catch (DomainException) {
                continue;
            }
        }

        return $created;
    }
}
