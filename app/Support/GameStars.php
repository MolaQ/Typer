<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Enums\MatchdayStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Gwiazdy gry (strona /stars): zawodnicy Lecha w klubach wszystkich graczy i botów, każdy wiersz = para klub + zawodnik
 * (dorobek liczy się osobno w każdym klubie, jak w „Mojej kadrze”). Liczby z rozliczonych wyborów (designated_players,
 * counted, mecze, które się odbyły), procent wygranych z meczów klubu w typie rozgrywek, w którym zawodnik był wystawiony
 * (Liga Legend: oba pojedynki; remis rozstrzygnięty czasem typu w pucharze = wygrana albo porażka).
 * Całość liczy baza (GROUP BY), strona filtruje, sortuje i stronicuje zapytanie: przy dużej liczbie klubów
 * wczytanie wszystkich wierszy do PHP wyczerpywało pamięć (15.10.2026).
 */
class GameStars
{
    /**
     * Zapytanie z jednym wierszem na parę klub + zawodnik (alias `stars`): user_id, bot_id, player_id, name, number, position,
     * is_academy, club, owner, fielded, played, goals, assists, own_goals, clean_sheets, attack, defense, contribution,
     * experience, yellow, red, won, games, win_pct.
     */
    public static function query(?int $seasonId): Builder
    {
        $base = DB::table('designated_players as dp')
            ->join('matchdays as m', 'm.id', '=', 'dp.matchday_id')
            ->leftJoinSub(self::results($seasonId), 'r', function (JoinClause $join): void {
                $join->on('r.matchday_id', '=', 'dp.matchday_id')
                    ->on('r.score_set', '=', 'dp.score_set')
                    ->on('r.club', '=', DB::raw('COALESCE(dp.user_id * 2, dp.bot_id * 2 + 1)'));
            })
            ->where('m.status', MatchdayStatus::Played->value)
            ->where('dp.counted', true)
            ->where('dp.matches', '>', 0)
            ->when($seasonId, fn ($q) => $q->where('m.season_id', $seasonId))
            ->groupBy('dp.user_id', 'dp.bot_id', 'dp.lech_player_id')
            ->selectRaw('dp.user_id, dp.bot_id, dp.lech_player_id as player_id,
                SUM(dp.matches) as fielded,
                SUM(CASE WHEN dp.play_time > 0 THEN dp.matches ELSE 0 END) as played,
                SUM(dp.goals * dp.matches) as goals,
                SUM(dp.assists * dp.matches) as assists,
                SUM(dp.own_goals * dp.matches) as own_goals,
                SUM(CASE WHEN dp.clean_sheet = 1 THEN dp.matches ELSE 0 END) as clean_sheets,
                SUM(dp.attack * dp.matches) as attack,
                SUM(dp.defense * dp.matches) as defense,
                SUM((dp.attack + dp.defense) * dp.matches) as contribution,
                SUM(dp.experience * dp.matches) as experience,
                SUM(dp.yellow * dp.matches) as yellow,
                SUM(dp.red * dp.matches) as red,
                COALESCE(SUM(r.won), 0) as won,
                COALESCE(SUM(r.games), 0) as games,
                CASE WHEN SUM(r.games) > 0 THEN ROUND(100.0 * SUM(r.won) / SUM(r.games), 1) END as win_pct');

        return DB::query()->fromSub($base, 's')
            ->join('lech_players as lp', 'lp.id', '=', 's.player_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('bots as b', 'b.id', '=', 's.bot_id')
            ->where(fn ($q) => $q->whereNotNull('u.id')->orWhereNotNull('b.id'))
            ->select('s.*', 'lp.name', 'lp.number', 'lp.position', 'lp.is_academy', 'u.name as owner')
            ->selectRaw("COALESCE(NULLIF(u.team_name, ''), u.name, b.name) as club");
    }

    /**
     * Liczby do banera i liderzy sezonu (pamięć PageCache, same liczby i id).
     *
     * @return array{count: int, goals: int, leaders: array<string, array<string, mixed>|null>}
     */
    public static function summary(?int $seasonId): array
    {
        return PageCache::remember('stars:summary:'.($seasonId ?? 'all'), function () use ($seasonId): array {
            $totals = DB::query()->fromSub(self::query($seasonId), 't')->selectRaw('COUNT(*) as n, COALESCE(SUM(goals), 0) as g')->first();
            $leaders = [];
            foreach (['goals', 'assists', 'clean_sheets', 'contribution', 'win_pct'] as $key) {
                $row = DB::query()->fromSub(self::query($seasonId), 't')
                    ->when($key === 'win_pct', fn ($q) => $q->where('games', '>=', 5))
                    ->where($key, '>', 0)
                    ->orderByDesc($key)->orderByDesc('fielded')
                    ->first();
                $leaders[$key] = $row ? (array) $row : null;
            }

            return ['count' => (int) ($totals->n ?? 0), 'goals' => (int) ($totals->g ?? 0), 'leaders' => $leaders];
        });
    }

    /**
     * Wygrane i mecze klubu w kolejce w typie rozgrywek: matchday_id, club (user_id × 2 albo bot_id × 2 + 1, bez liczb ujemnych: kolumny są unsigned), score_set, won, games.
     * Właściciel strony meczu = kto grał miejscem w tej kolejce (team_scores.user_id / bot_id).
     */
    private static function results(?int $seasonId): Builder
    {
        // Typ rozgrywek → typ dorobku (CompetitionType::scoreSet) jako CASE w SQL.
        $set = 'CASE c.type';
        foreach (CompetitionType::cases() as $type) {
            $set .= " WHEN '".$type->value."' THEN '".$type->scoreSet()->value."'";
        }
        $set .= ' END';

        $side = function (string $mine, string $theirs, string $entry) use ($seasonId, $set): Builder {
            return DB::table('fixtures as f')
                ->join('competitions as c', 'c.id', '=', 'f.competition_id')
                ->join('matchdays as fm', fn (JoinClause $j) => $j->on('fm.season_id', '=', 'c.season_id')->on('fm.number', '=', 'f.round'))
                ->join('competition_entries as e', 'e.id', '=', 'f.'.$entry)
                ->join('team_scores as ts', fn (JoinClause $j) => $j->on('ts.matchday_id', '=', 'fm.id')
                    ->on('ts.season_team_id', '=', 'e.season_team_id')
                    ->on('ts.score_set', '=', DB::raw($set)))
                ->whereNotNull('f.home_goals')
                ->when($seasonId, fn ($q) => $q->where('c.season_id', $seasonId))
                ->selectRaw("fm.id as matchday_id, COALESCE(ts.user_id * 2, ts.bot_id * 2 + 1) as club, ts.score_set,
                    CASE WHEN f.$mine > f.$theirs OR (f.$mine = f.$theirs AND f.winner_entry_id = f.$entry) THEN 1 ELSE 0 END as won");
        };

        $sides = $side('home_goals', 'away_goals', 'home_entry_id')->unionAll($side('away_goals', 'home_goals', 'away_entry_id'));

        return DB::query()->fromSub($sides, 'x')
            ->groupBy('matchday_id', 'club', 'score_set')
            ->selectRaw('matchday_id, club, score_set, SUM(won) as won, COUNT(*) as games');
    }
}
