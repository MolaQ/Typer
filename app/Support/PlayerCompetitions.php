<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Models\Competition;

/**
 * W jakich typach rozgrywek sezonu gra dany użytkownik (CompetitionType::scoreSet)?
 * Liga i Liga podwórkowa liczą się razem, więc obie zwracamy jako League.
 * Zespół, który odpadł z pucharu, pucharu europejskiego albo z Ligi Legend, już w nich nie gra
 * (w kolejce, w której odpadł, jeszcze tak).
 */
class PlayerCompetitions
{
    /** @return array<int, CompetitionType> w kolejności z enuma */
    public static function types(int $userId, int $seasonId, ?int $round = null): array
    {
        $alive = fn ($q) => $round === null
            ? $q->whereNull('eliminated_round')
            : $q->where(fn ($w) => $w->whereNull('eliminated_round')->orWhere('eliminated_round', '>=', $round));

        $present = Competition::where('season_id', $seasonId)
            ->whereHas('entries', fn ($q) => $alive($q)->whereHas('seasonTeam', fn ($t) => $t->where('user_id', $userId)))
            ->pluck('type')
            ->map(fn ($t) => ($t instanceof CompetitionType ? $t : CompetitionType::from($t))->scoreSet())
            ->all();

        return array_values(array_filter(CompetitionType::cases(), fn ($t) => in_array($t, $present, true)));
    }
}
