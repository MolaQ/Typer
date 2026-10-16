<?php

namespace App\Support;

use App\Enums\MatchdayStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Gwiazdy gry (strona /stars): zawodnicy Lecha w drużynach graczy (bez botów, 16.10.2026), każdy wiersz = para drużyna + zawodnik
 * (dorobek liczy się osobno w każdej drużynie, jak w „Mojej kadrze”). Liczby z rozliczonych wyborów (designated_players,
 * counted, mecze, które się odbyły), procent wygranych z meczów drużyny w typie rozgrywek, w którym zawodnik był wystawiony
 * (Liga Legend: oba pojedynki; remis rozstrzygnięty czasem typu w pucharze = wygrana albo porażka).
 * Sumy leżą gotowe w `star_stats` (sezon + wiersze season_id = 0 dla wszystkich sezonów), przeliczane po każdej kolejce
 * (`rebuild`, ScoreMatchday) i poleceniem `php artisan stars:rebuild`; strona tylko filtruje i stronicuje tę tabelę.
 */
class GameStars
{
    /** Typ rozgrywek → typ dorobku (CompetitionType::scoreSet) w SQL: Liga podwórkowa z ligami, LE i LK z LM. */
    public const SCORE_SET_SQL = "CASE c.type WHEN 'swiss' THEN 'league' WHEN 'europa' THEN 'champions' WHEN 'conference' THEN 'champions' ELSE c.type END";

    /** Kolumny star_stats wypełniane przy przeliczeniu. */
    private const COLUMNS = ['season_id', 'user_id', 'lech_player_id', 'position', 'fielded', 'played', 'goals', 'assists', 'own_goals',
        'clean_sheets', 'attack', 'defense', 'contribution', 'experience', 'yellow', 'red', 'won', 'games', 'win_pct'];

    /**
     * Tabela strony: wiersze star_stats sezonu (0 = wszystkie) z nazwą i numerem zawodnika oraz nazwą drużyny.
     * Kolumny: user_id, player_id, name, number, position, is_academy, club, owner i liczby.
     */
    public static function query(?int $seasonId): Builder
    {
        return DB::table('star_stats as s')
            ->join('lech_players as lp', 'lp.id', '=', 's.lech_player_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.season_id', $seasonId ?? 0)
            ->select('s.*', 's.lech_player_id as player_id', 'lp.name', 'lp.number', 'lp.is_academy', 'u.name as owner')
            ->selectRaw("COALESCE(NULLIF(u.team_name, ''), u.name) as club");
    }

    /**
     * Liczby do banera i liderzy sezonu (pamięć PageCache, same liczby i id).
     *
     * @return array{count: int, goals: int, leaders: array<string, array<string, mixed>|null>}
     */
    public static function summary(?int $seasonId): array
    {
        return PageCache::remember('stars:summary:'.($seasonId ?? 'all'), function () use ($seasonId): array {
            $totals = DB::table('star_stats')->where('season_id', $seasonId ?? 0)->selectRaw('COUNT(*) as n, COALESCE(SUM(goals), 0) as g')->first();
            $leaders = [];
            foreach (['goals', 'assists', 'clean_sheets', 'contribution', 'win_pct'] as $key) {
                $row = self::query($seasonId)
                    ->when($key === 'win_pct', fn ($q) => $q->where('s.games', '>=', 5))
                    ->where('s.'.$key, '>', 0)
                    ->orderByDesc('s.'.$key)->orderByDesc('s.fielded')
                    ->first();
                $leaders[$key] = $row ? (array) $row : null;
            }

            return ['count' => (int) ($totals->n ?? 0), 'goals' => (int) ($totals->g ?? 0), 'leaders' => $leaders];
        });
    }

    /** Przelicza sezon i sumę wszystkich sezonów (po rozliczeniu kolejki, po zatwierdzeniu transakcji). */
    public static function rebuild(int $seasonId): void
    {
        DB::transaction(function () use ($seasonId): void {
            DB::table('star_stats')->where('season_id', $seasonId)->delete();
            DB::table('star_stats')->insertUsing(self::COLUMNS, self::base($seasonId));
            self::rebuildAll();
        });
        PageCache::flush();
    }

    /** Przelicza wszystkie sezony od zera (polecenie stars:rebuild). */
    public static function rebuildEverything(): int
    {
        $seasons = DB::table('matchdays')->where('status', MatchdayStatus::Played->value)->distinct()->pluck('season_id');

        DB::transaction(function () use ($seasons): void {
            DB::table('star_stats')->delete();
            foreach ($seasons as $seasonId) {
                DB::table('star_stats')->insertUsing(self::COLUMNS, self::base((int) $seasonId));
            }
            self::rebuildAll();
        });
        PageCache::flush();

        return $seasons->count();
    }

    /** Wiersze season_id = 0: suma wierszy sezonów. */
    private static function rebuildAll(): void
    {
        DB::table('star_stats')->where('season_id', 0)->delete();
        DB::table('star_stats')->insertUsing(self::COLUMNS, DB::table('star_stats')
            ->where('season_id', '>', 0)
            ->groupBy('user_id', 'lech_player_id', 'position')
            ->selectRaw('0, user_id, lech_player_id, position, SUM(fielded), SUM(played), SUM(goals), SUM(assists), SUM(own_goals),
                SUM(clean_sheets), SUM(attack), SUM(defense), SUM(contribution), SUM(experience), SUM(yellow), SUM(red),
                SUM(won), SUM(games), CASE WHEN SUM(games) > 0 THEN ROUND(100.0 * SUM(won) / SUM(games), 1) END'));
    }

    /** Sumy sezonu z designated_players w kolejności COLUMNS (tylko drużyny graczy). */
    private static function base(int $seasonId): Builder
    {
        return DB::table('designated_players as dp')
            ->join('matchdays as m', 'm.id', '=', 'dp.matchday_id')
            ->join('lech_players as lp', 'lp.id', '=', 'dp.lech_player_id')
            ->join('users as u', 'u.id', '=', 'dp.user_id')
            ->leftJoinSub(self::results($seasonId), 'r', function (JoinClause $join): void {
                $join->on('r.matchday_id', '=', 'dp.matchday_id')
                    ->on('r.score_set', '=', 'dp.score_set')
                    ->on('r.user_id', '=', 'dp.user_id');
            })
            ->where('m.status', MatchdayStatus::Played->value)
            ->where('m.season_id', $seasonId)
            ->where('dp.counted', true)
            ->where('dp.matches', '>', 0)
            ->groupBy('dp.user_id', 'dp.lech_player_id', 'lp.position')
            ->selectRaw('m.season_id, dp.user_id, dp.lech_player_id, lp.position,
                SUM(dp.matches), SUM(CASE WHEN dp.play_time > 0 THEN dp.matches ELSE 0 END),
                SUM(dp.goals * dp.matches), SUM(dp.assists * dp.matches), SUM(dp.own_goals * dp.matches),
                SUM(CASE WHEN dp.clean_sheet = 1 THEN dp.matches ELSE 0 END),
                SUM(dp.attack * dp.matches), SUM(dp.defense * dp.matches), SUM((dp.attack + dp.defense) * dp.matches),
                SUM(dp.experience * dp.matches), SUM(dp.yellow * dp.matches), SUM(dp.red * dp.matches),
                COALESCE(SUM(r.won), 0), COALESCE(SUM(r.games), 0),
                CASE WHEN SUM(r.games) > 0 THEN ROUND(100.0 * SUM(r.won) / SUM(r.games), 1) END')
            ->groupBy('m.season_id');
    }

    /**
     * Wygrane i mecze drużyny gracza w kolejce w typie rozgrywek: matchday_id, user_id, score_set, won, games.
     * Właściciel strony meczu = kto grał miejscem w tej kolejce (team_scores.user_id / bot_id).
     */
    private static function results(int $seasonId): Builder
    {
        $sides = self::side($seasonId, 'home_entry_id', 'fm.id as matchday_id, ts.user_id, ts.score_set,
            CASE WHEN f.home_goals > f.away_goals OR (f.home_goals = f.away_goals AND f.winner_entry_id = f.home_entry_id) THEN 1 ELSE 0 END as won')
            ->unionAll(self::side($seasonId, 'away_entry_id', 'fm.id as matchday_id, ts.user_id, ts.score_set,
            CASE WHEN f.away_goals > f.home_goals OR (f.home_goals = f.away_goals AND f.winner_entry_id = f.away_entry_id) THEN 1 ELSE 0 END as won'));

        return DB::query()->fromSub($sides, 'x')
            ->groupBy('matchday_id', 'user_id', 'score_set')
            ->selectRaw('matchday_id, user_id, score_set, SUM(won) as won, COUNT(*) as games');
    }

    /**
     * Jedna strona meczów (gospodarz albo gość) z wynikiem dla właściciela miejsca.
     *
     * @param  literal-string  $select
     */
    private static function side(int $seasonId, string $entry, string $select): Builder
    {
        return DB::table('fixtures as f')
            ->join('competitions as c', 'c.id', '=', 'f.competition_id')
            ->join('matchdays as fm', fn (JoinClause $j) => $j->on('fm.season_id', '=', 'c.season_id')->on('fm.number', '=', 'f.round'))
            ->join('competition_entries as e', 'e.id', '=', 'f.'.$entry)
            ->join('team_scores as ts', fn (JoinClause $j) => $j->on('ts.matchday_id', '=', 'fm.id')
                ->on('ts.season_team_id', '=', 'e.season_team_id')
                ->on('ts.score_set', '=', DB::raw(self::SCORE_SET_SQL)))
            ->whereNotNull('f.home_goals')
            ->whereNotNull('ts.user_id')
            ->where('c.season_id', $seasonId)
            ->selectRaw($select);
    }
}
