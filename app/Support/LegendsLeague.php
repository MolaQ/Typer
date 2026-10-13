<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Fixture;
use App\Models\Matchday;
use Illuminate\Support\Facades\DB;

/**
 * Liga Legend (regulamin, punkt 10): wszystkie zespoły ludzi, tabela ligowa (3, 1, 0 i kryteria jak w ligach, Standings),
 * wyniki narastająco od kolejki 1.
 *  - W każdej kolejce każdy grający zespół rozgrywa dwa pojedynki w cyklu: 1-2, 2-3, …, (N-1)-N i 1-N
 *    (po lewej zespół wyżej). Kolejka 1 z listy przedsezonowej (rozstawienie), kolejne według aktualnej tabeli.
 *    Przy 2 zespołach para 1-2 i 1-N to ten sam mecz, więc gramy jeden.
 *  - Dwie porażki w jednej kolejce: zespół odpada (eliminated_round = kolejka). Jego wyniki zostają w tabeli,
 *    ale dalej już nie gra. Remis to nie porażka.
 *  - Po 9. kolejce wygrywa zespół z 1. miejsca całej tabeli, także jeśli wcześniej odpadł.
 * Mecze nie mają miejsc (seats): zespół 1 jest gospodarzem dwa razy, a unikalne indeksy miejsc dopuszczają null.
 */
final class LegendsLeague
{
    public const ROUNDS = 9;

    /** Tyle porażek w jednej kolejce eliminuje zespół. */
    public const LOSSES_OUT = 2;

    /**
     * Pary kolejki z listy zespołów od najlepszego: 1-2, 2-3, …, (N-1)-N i 1-N. Przy 2 zespołach jeden mecz.
     *
     * @param  list<int>  $ranked  id wpisów od najlepszego
     * @return list<array{0: int, 1: int}> [gospodarz, gość]
     */
    public static function pairs(array $ranked): array
    {
        $count = count($ranked);
        $pairs = [];

        for ($i = 0; $i < $count - 1; $i++) {
            $pairs[] = [$ranked[$i], $ranked[$i + 1]];
        }

        if ($count >= 3) {
            $pairs[] = [$ranked[0], $ranked[$count - 1]];
        }

        return $pairs;
    }

    /**
     * Wpisy, które przegrały co najmniej LOSSES_OUT meczów w tej kolejce.
     *
     * @param  iterable<Fixture>  $fixtures  rozegrane mecze jednej kolejki
     * @return list<int> id wpisów
     */
    public static function losers(iterable $fixtures): array
    {
        $losses = [];

        foreach ($fixtures as $fixture) {
            if (! $fixture->isPlayed() || $fixture->away_entry_id === null || $fixture->home_goals === $fixture->away_goals) {
                continue;
            }

            $loser = $fixture->home_goals < $fixture->away_goals ? $fixture->home_entry_id : $fixture->away_entry_id;
            $losses[$loser] = ($losses[$loser] ?? 0) + 1;
        }

        return array_map('intval', array_keys(array_filter($losses, fn (int $count) => $count >= self::LOSSES_OUT)));
    }

    /**
     * Odpadnięcia po przeliczonej kolejce (ScoreMatchday, które wcześniej cofa odpadnięcia z tej kolejki).
     * Po ostatniej kolejce nikt już nie odpada.
     */
    public static function eliminate(Competition $competition, int $round): int
    {
        if ($round >= self::ROUNDS) {
            return 0;
        }

        $fixtures = Fixture::where('competition_id', $competition->id)->where('round', $round)->get();
        $out = self::losers($fixtures);

        if ($out !== []) {
            CompetitionEntry::whereIn('id', $out)->update(['eliminated_round' => $round]);
        }

        return count($out);
    }

    /**
     * Losuje pary kolejki $round (kasuje jej mecze bez wyników). Grają zespoły ludzi, które nie odpadły przed tą kolejką,
     * kolejka 1 według rozstawienia, następne według tabeli.
     *
     * @return int liczba utworzonych meczów (0, gdy kolejka ma już wyniki)
     */
    public static function draw(Competition $competition, int $round): int
    {
        if ($competition->type !== CompetitionType::Legends || $round < 1 || $round > self::ROUNDS) {
            return 0;
        }

        return DB::transaction(function () use ($competition, $round): int {
            $existing = Fixture::where('competition_id', $competition->id)->where('round', $round);

            if ((clone $existing)->whereNotNull('home_goals')->exists()) {
                return 0;
            }

            $existing->delete();

            $alive = CompetitionEntry::where('competition_id', $competition->id)
                ->where(fn ($q) => $q->whereNull('eliminated_round')->orWhere('eliminated_round', '>=', $round))
                ->whereHas('seasonTeam', fn ($q) => $q->whereNotNull('user_id'))
                ->orderBy('seed')
                ->pluck('id')
                ->all();

            $ranked = $round === 1
                ? $alive
                : array_values(array_intersect(Standings::rankedEntryIds($competition), $alive));

            $now = now();
            $rows = array_map(fn (array $pair) => [
                'competition_id' => $competition->id,
                'round' => $round,
                'home_seat' => null,
                'away_seat' => null,
                'home_entry_id' => $pair[0],
                'away_entry_id' => $pair[1],
                'created_at' => $now,
                'updated_at' => $now,
            ], self::pairs(array_values(array_map('intval', $ranked))));

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('fixtures')->insert($chunk);
            }

            return count($rows);
        });
    }

    /** Po przeliczeniu kolejki: pary następnej kolejki według nowej tabeli (SaveMatchdayResult). */
    public static function drawNext(Matchday $matchday): int
    {
        $competition = Competition::where('season_id', $matchday->season_id)->where('type', CompetitionType::Legends->value)->first();

        return $competition && $matchday->number < self::ROUNDS ? self::draw($competition, $matchday->number + 1) : 0;
    }

    /**
     * Po zmianie składu (gracz dołączył albo odszedł w trakcie sezonu): ponowne losowanie najbliższej kolejki bez wyników,
     * jeśli jej pary już istnieją albo to kolejka 1.
     */
    public static function redrawPending(Competition $competition): int
    {
        $played = (int) Fixture::where('competition_id', $competition->id)->whereNotNull('home_goals')->max('round');
        $next = $played + 1;

        if ($next > self::ROUNDS) {
            return 0;
        }

        $drawn = Fixture::where('competition_id', $competition->id)->where('round', $next)->exists();

        return $next === 1 || $drawn ? self::draw($competition, $next) : 0;
    }
}
