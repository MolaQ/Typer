<?php

namespace App\Support;

use App\Models\Matchday;
use App\Models\Tip;

/**
 * Okno meczu Lecha na stronie wyników i typowanie premium: ciekawostki o typach graczy.
 * Liczymy tylko typy graczy (bez botów). Statystyki typów pokazujemy tylko premium.
 */
final class MatchdayInsights
{
    /**
     * Statystyki typów kolejki. Po wyniku dochodzą trafienia i najczęstsze wyniki.
     *
     * @return array{count: int, lech: int, draw: int, opponent: int, avg_lech: ?float, avg_opponent: ?float,
     *   exact: ?int, outcome: ?int, diff: ?int, popular: array<int, array{score: string, count: int}>, exact_count: ?int}
     */
    public static function tips(Matchday $matchday): array
    {
        $tips = Tip::where('matchday_id', $matchday->id)->get(['lech_goals', 'opponent_goals']);
        $count = $tips->count();
        $played = $matchday->lech_goals !== null && $matchday->opponent_goals !== null;

        $out = [
            'count' => $count,
            'lech' => $tips->filter(fn (Tip $t) => $t->lech_goals > $t->opponent_goals)->count(),
            'draw' => $tips->filter(fn (Tip $t) => $t->lech_goals === $t->opponent_goals)->count(),
            'opponent' => $tips->filter(fn (Tip $t) => $t->lech_goals < $t->opponent_goals)->count(),
            'avg_lech' => $count > 0 ? round((float) $tips->avg('lech_goals'), 1) : null,
            'avg_opponent' => $count > 0 ? round((float) $tips->avg('opponent_goals'), 1) : null,
            'exact' => null, 'outcome' => null, 'diff' => null, 'exact_count' => null,
            // Najczęstsze typy (do trzech), od najpopularniejszego.
            'popular' => $tips->countBy(fn (Tip $t) => $t->lech_goals.':'.$t->opponent_goals)
                ->sortDesc()
                ->take(3)
                ->map(fn (int $n, string $score) => ['score' => $score, 'count' => $n])
                ->values()
                ->all(),
        ];

        if ($played && $count > 0) {
            $results = $tips->map(fn (Tip $t) => Scoring::tip($t->lech_goals, $t->opponent_goals, (int) $matchday->lech_goals, (int) $matchday->opponent_goals));
            $out['exact_count'] = $results->where('exact', true)->count();
            $out['exact'] = self::percent($out['exact_count'], $count);
            $out['outcome'] = self::percent($results->where('outcome', true)->count(), $count);
            $out['diff'] = self::percent($results->where('diff', true)->count(), $count);
        }

        return $out;
    }

    /** Procent zaokrąglony do całości, null przy braku danych. */
    private static function percent(int $part, int $total): ?int
    {
        return $total > 0 ? (int) round($part / $total * 100) : null;
    }
}
