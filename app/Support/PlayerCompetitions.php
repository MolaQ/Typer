<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Models\Competition;

/**
 * W jakich typach rozgrywek sezonu gra dany użytkownik? Od tego zależy, na jakie zestawy pytań
 * bonusowych odpowiada (jeden zestaw 5+5 na każdy typ rozgrywek, w którym gra).
 */
class PlayerCompetitions
{
    /** @return array<int, CompetitionType> w kolejności z enuma */
    public static function types(int $userId, int $seasonId): array
    {
        $present = Competition::where('season_id', $seasonId)
            ->whereHas('entries.seasonTeam', fn($q) => $q->where('user_id', $userId))
            ->pluck('type')
            ->map(fn($t) => $t instanceof CompetitionType ? $t : CompetitionType::from($t))
            ->all();

        return array_values(array_filter(CompetitionType::cases(), fn($t) => in_array($t, $present, true)));
    }
}