<?php

namespace App\Support;

use App\Models\SeasonTeam;
use App\Models\TeamScore;

/**
 * Kto grał miejscem z listy sezonu w danej kolejce: gracz ('u12') albo bot ('b34').
 *
 * Gracz, który w trakcie sezonu wskoczy za bota, przejmuje wynik miejsca w tabeli i pucharze, ale statystyki,
 * skalpy, bilanse bezpośrednie i punkty Hall of Fame liczą się temu, kto naprawdę grał daną kolejkę.
 * Źródło: team_scores.user_id / bot_id zapisane przy rozliczeniu kolejki (ScoreMatchday). Kolejka bez rozliczenia
 * należy do obecnego właściciela miejsca.
 */
final class Ownership
{
    /** @var array<int, array<int, array<int, string>>> sezon => miejsce => kolejka => właściciel */
    private static array $cache = [];

    /** @var array<int, array<int, string|null>> sezon => miejsce => obecny właściciel */
    private static array $current = [];

    public static function key(?int $userId, ?int $botId): ?string
    {
        return $userId ? 'u'.$userId : ($botId ? 'b'.$botId : null);
    }

    /** Właściciel miejsca w kolejce (numer kolejki = runda meczu). */
    public static function at(int $seasonId, int $seasonTeamId, int $round): ?string
    {
        self::load($seasonId);

        return self::$cache[$seasonId][$seasonTeamId][$round] ?? (self::$current[$seasonId][$seasonTeamId] ?? null);
    }

    /** Czy gracz grał tym miejscem w tej kolejce. */
    public static function playedBy(int $seasonId, int $seasonTeamId, int $round, int $userId): bool
    {
        return self::at($seasonId, $seasonTeamId, $round) === 'u'.$userId;
    }

    /** Po przeliczeniu kolejki albo zmianie właściciela (w jednym żądaniu). */
    public static function flush(): void
    {
        self::$cache = [];
        self::$current = [];
    }

    private static function load(int $seasonId): void
    {
        if (isset(self::$cache[$seasonId])) {
            return;
        }

        $map = [];
        foreach (TeamScore::query()
            ->join('matchdays', 'matchdays.id', '=', 'team_scores.matchday_id')
            ->where('matchdays.season_id', $seasonId)
            ->distinct()
            ->get(['team_scores.season_team_id', 'team_scores.user_id', 'team_scores.bot_id', 'matchdays.number']) as $row) {
            $map[(int) $row->season_team_id][(int) $row->getAttribute('number')] = self::key($row->user_id, $row->bot_id);
        }

        $current = [];
        foreach (SeasonTeam::where('season_id', $seasonId)->get(['id', 'user_id', 'bot_id']) as $team) {
            $current[$team->id] = self::key($team->user_id, $team->bot_id);
        }

        self::$cache[$seasonId] = array_map(fn (array $rounds) => array_filter($rounds), $map);
        self::$current[$seasonId] = $current;
    }
}
