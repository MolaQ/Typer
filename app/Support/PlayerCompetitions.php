<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Models\Competition;

/**
 * W jakich typach rozgrywek sezonu gra dany użytkownik? Od tego zależy, na jakie zestawy pytań
 * bonusowych odpowiada (jeden zestaw 5+5 na każdy typ rozgrywek, w którym gra).
 * Liga i Liga podwórkowa mają wspólny zestaw, więc obie zwracamy jako League.
 */
class PlayerCompetitions
{
    /** @return array<int, CompetitionType> w kolejności z enuma */
    public static function types(int $userId, int $seasonId): array
    {
        $present = Competition::where('season_id', $seasonId)
            // Zespół, który odpadł z Ligi Legend, nie odpowiada już na jej pytania.
            ->whereHas('entries', fn($q) => $q->whereNull('eliminated_round')->whereHas('seasonTeam', fn($t) => $t->where('user_id', $userId)))
            ->pluck('type')
            ->map(fn($t) => ($t instanceof CompetitionType ? $t : CompetitionType::from($t))->questionSet())
            ->all();

        return array_values(array_filter(CompetitionType::cases(), fn($t) => in_array($t, $present, true)));
    }
}