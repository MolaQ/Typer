<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Enums\League;
use App\Enums\MatchdayStatus;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\FinalStanding;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\TeamScore;
use App\Models\Tip;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Statystyki gracza do zakładek strony „Moje typy”: kolejki (typy i braki), postęp w rozgrywkach,
 * statystyki sezonu (typy, pytania, mecze, serie, miejsce wśród graczy) i historia z rekordami osobistymi.
 * Liczymy z gotowych danych: team_scores (rozliczone kolejki), fixtures (mecze) i final_standings (tabele końcowe).
 */
final class PlayerStats
{
    /**
     * Kolejki sezonu z typem gracza i punktami (rozliczone) albo informacją o braku typu.
     *
     * @return array<int, array{matchday: Matchday, tip: ?string, default: bool, points: ?int, exact: bool, missing: bool, open: bool}>
     */
    public static function matchdays(User $user, Season $season): array
    {
        $matchdays = Matchday::where('season_id', $season->id)->orderBy('number')->get();
        $tips = Tip::whereIn('matchday_id', $matchdays->pluck('id'))->where('user_id', $user->id)->get()->keyBy('matchday_id');
        $scores = self::tipRows($user, $season)->keyBy('matchday_id');

        return $matchdays->map(function (Matchday $matchday) use ($tips, $scores) {
            $tip = $tips->get($matchday->id);
            $score = $scores->get($matchday->id);
            $played = $matchday->status === MatchdayStatus::Played;

            return [
                'matchday' => $matchday,
                'tip' => $tip?->score(),
                'default' => (bool) $tip?->is_default,
                'points' => $played && $score ? (int) $score->tip_points : null,
                'exact' => (bool) $score?->exact_hit,
                // Brak typu: kolejka rozegrana bez typu albo jeszcze otwarta i nie wytypowana.
                'missing' => $tip === null && ($played || $matchday->isOpenForTips()),
                'open' => $matchday->isOpenForTips(),
            ];
        })->all();
    }

    /**
     * Postęp w rozgrywkach sezonu: miejsce w tabeli albo etap (puchar, Liga Legend).
     *
     * @return array<int, array{name: string, type: CompetitionType, status: string, played: int, won: int, drawn: int, lost: int, points: ?int}>
     */
    public static function competitions(User $user, Season $season): array
    {
        $team = self::team($user, $season);

        if (!$team) {
            return [];
        }

        $entries = CompetitionEntry::with('competition')->where('season_team_id', $team->id)->get();
        $order = array_map(fn($t) => $t->value, CompetitionType::cases());
        $out = [];

        foreach ($entries->sortBy(fn($e) => [array_search($e->competition->type->value, $order, true), $e->competition->tier ?? 0]) as $entry) {
            $competition = $entry->competition;
            $row = [
                'name' => $competition->name ?: $competition->type->label(),
                'type' => $competition->type,
                'status' => '',
                'played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0,
                'points' => null,
            ];

            if ($competition->type === CompetitionType::Cup) {
                $row['status'] = $entry->eliminated_round
                    ? __('Knocked out in: :round', ['round' => CupBracket::roundName($entry->eliminated_round)])
                    : __('Still in the cup');
            } elseif ($competition->type === CompetitionType::Legends) {
                $row['status'] = $entry->eliminated_round
                    ? __('Knocked out after matchday :number', ['number' => $entry->eliminated_round])
                    : __('Still in the game');
            } else {
                $table = Standings::for($competition)->values();
                $index = $table->search(fn($r) => $r['entry_id'] === $entry->id);
                $own = $index === false ? null : $table[$index];

                $row['status'] = $index === false ? '—' : __(':place. place of :count', ['place' => $index + 1, 'count' => $table->count()]);
                $row['points'] = $own['points'] ?? null;
                foreach (['played', 'won', 'drawn', 'lost'] as $key) {
                    $row[$key] = (int) ($own[$key] ?? 0);
                }
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * Statystyki sezonu gracza, a obok średnie wszystkich graczy (do kolorów kafelków).
     *
     * @return array{mine: array<string, mixed>, average: array<string, float>, rank: ?array{place: int, count: int}}
     */
    public static function season(User $user, Season $season): array
    {
        $all = self::seasonAll($season);
        $team = self::team($user, $season);
        $mine = $team ? ($all[$team->id] ?? self::empty()) : self::empty();

        $average = [];
        if ($all !== []) {
            foreach (array_keys(self::empty()) as $key) {
                if (is_numeric($mine[$key] ?? null) || ($mine[$key] ?? null) === null) {
                    $values = array_filter(array_column($all, $key), fn($v) => $v !== null && is_numeric($v));
                    $average[$key] = $values === [] ? 0.0 : array_sum($values) / count($values);
                }
            }
        }

        // Miejsce wśród graczy według punktów za typy (pokazuje premium).
        $rank = null;
        if ($team && isset($all[$team->id]) && $all[$team->id]['scored'] > 0) {
            $points = $all[$team->id]['tip_points'];
            $rank = [
                'place' => count(array_filter($all, fn($row) => $row['tip_points'] > $points)) + 1,
                'count' => count($all),
            ];
        }

        return ['mine' => $mine, 'average' => $average, 'rank' => $rank];
    }

    /**
     * Kolor kafelka względem średniej graczy: zielony powyżej, czerwony poniżej, żółty w granicach ±15%.
     */
    public static function color(?float $value, ?float $average): string
    {
        if ($value === null || $average === null) {
            return 'zinc';
        }

        $margin = abs($average) * 0.15;

        return match (true) {
            $value > $average + $margin => 'green',
            $value < $average - $margin => 'red',
            default => 'yellow',
        };
    }

    /** @var array<int, array<int, array<string, mixed>>> */
    private static array $seasonCache = [];

    /**
     * Statystyki wszystkich zespołów ludzi w sezonie: id zespołu => statystyki.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function seasonAll(Season $season): array
    {
        if (isset(self::$seasonCache[$season->id])) {
            return self::$seasonCache[$season->id];
        }

        $teamIds = SeasonTeam::where('season_id', $season->id)->whereNotNull('user_id')->pluck('id');

        $rows = TeamScore::query()
            ->join('matchdays', 'matchdays.id', '=', 'team_scores.matchday_id')
            ->whereIn('team_scores.season_team_id', $teamIds)
            ->select('team_scores.*', 'matchdays.number')
            ->get()
            ->groupBy('season_team_id');

        $matches = self::seasonMatches($season, $teamIds);
        $out = [];

        foreach ($teamIds as $teamId) {
            $all = $rows->get($teamId, collect());
            $perMatchday = $all->unique('matchday_id')->sortBy('number')->values();
            $tipped = $perMatchday->where('has_tip', true);
            $tips = $tipped->count();
            $withTip = $all->where('has_tip', true);
            [$streak, $streakBest] = self::streak($perMatchday->map(fn($r) => (bool) $r->outcome_hit)->all());
            $m = $matches[$teamId] ?? self::matchSummary([]);

            $out[$teamId] = [
                'scored' => $perMatchday->count(),
                'tips' => $tips,
                'tips_ratio' => $perMatchday->count() > 0 ? $tips / $perMatchday->count() : null,
                'missing' => $perMatchday->count() - $tips,
                'exact' => $tipped->where('exact_hit', true)->count(),
                'diff' => $tipped->where('diff_hit', true)->count(),
                'outcome' => $tipped->where('outcome_hit', true)->count(),
                'accuracy' => $tips > 0 ? round($tipped->where('outcome_hit', true)->count() / $tips * 100) : null,
                'tip_points' => (int) $tipped->sum('tip_points'),
                'avg_tip' => $tips > 0 ? round($tipped->avg('tip_points'), 2) : null,
                'avg_offense' => $withTip->isNotEmpty() ? round($withTip->avg('offense_bonus'), 2) : null,
                'avg_defense' => $withTip->isNotEmpty() ? round($withTip->avg('defense_bonus'), 2) : null,
                'zeroed' => $withTip->where('offense_zeroed', true)->count() + $withTip->where('defense_zeroed', true)->count(),
                'outcome_streak' => $streak,
                'outcome_streak_best' => $streakBest,
            ] + $m;
        }

        return self::$seasonCache[$season->id] = $out;
    }

    /** Puste statystyki (gracz bez rozliczonych kolejek). */
    private static function empty(): array
    {
        return [
            'scored' => 0, 'tips' => 0, 'tips_ratio' => null, 'missing' => 0, 'exact' => 0, 'diff' => 0, 'outcome' => 0,
            'accuracy' => null, 'tip_points' => 0, 'avg_tip' => null, 'avg_offense' => null, 'avg_defense' => null,
            'zeroed' => 0, 'outcome_streak' => 0, 'outcome_streak_best' => 0,
        ] + self::matchSummary([]);
    }

    /**
     * Mecze sezonu pogrupowane na zespoły: id zespołu => podsumowanie meczów.
     *
     * @param  Collection<int, int>  $teamIds
     * @return array<int, array<string, mixed>>
     */
    private static function seasonMatches(Season $season, Collection $teamIds): array
    {
        $teamOfEntry = CompetitionEntry::whereIn('season_team_id', $teamIds)
            ->whereIn('competition_id', Competition::where('season_id', $season->id)->select('id'))
            ->pluck('season_team_id', 'id');

        $fixtures = Fixture::whereIn('competition_id', Competition::where('season_id', $season->id)->select('id'))
            ->whereNotNull('home_goals')
            ->orderBy('round')->orderBy('id')
            ->get();

        $perTeam = [];
        foreach ($fixtures as $fixture) {
            foreach ([[$fixture->home_entry_id, true], [$fixture->away_entry_id, false]] as [$entryId, $home]) {
                if ($entryId && isset($teamOfEntry[$entryId])) {
                    $perTeam[$teamOfEntry[$entryId]][] = self::result($fixture, $entryId, $home);
                }
            }
        }

        return array_map(fn($results) => self::matchSummary($results), $perTeam);
    }

    /** Wynik meczu z perspektywy wpisu: [W|D|L, gole zdobyte, stracone]. */
    private static function result(Fixture $fixture, int $entryId, bool $home): array
    {
        $mine = (int) ($home ? $fixture->home_goals : $fixture->away_goals);
        $theirs = (int) ($home ? $fixture->away_goals : $fixture->home_goals);

        // Remis rozstrzygnięty czasem typu (puchar) liczymy jako wygraną albo porażkę.
        if ($mine === $theirs && $fixture->winner_entry_id) {
            $outcome = (int) $fixture->winner_entry_id === $entryId ? 'W' : 'L';
        } else {
            $outcome = $mine > $theirs ? 'W' : ($mine === $theirs ? 'D' : 'L');
        }

        return [$outcome, $mine, $theirs];
    }

    /** @param array<int, array{0: string, 1: int, 2: int}> $results */
    private static function matchSummary(array $results): array
    {
        $outcomes = array_column($results, 0);
        [$unbeaten, $unbeatenBest] = self::streak(array_map(fn($r) => $r !== 'L', $outcomes));
        [$wins, $winsBest] = self::streak(array_map(fn($r) => $r === 'W', $outcomes));
        $played = count($results);
        $won = count(array_filter($outcomes, fn($r) => $r === 'W'));

        return [
            'played' => $played,
            'won' => $won,
            'drawn' => count(array_filter($outcomes, fn($r) => $r === 'D')),
            'lost' => count(array_filter($outcomes, fn($r) => $r === 'L')),
            'win_ratio' => $played > 0 ? $won / $played : null,
            'for' => array_sum(array_column($results, 1)),
            'against' => array_sum(array_column($results, 2)),
            'record_goals' => $results === [] ? 0 : max(array_column($results, 1)),
            'unbeaten' => $unbeaten, 'unbeaten_best' => $unbeatenBest,
            'wins' => $wins, 'wins_best' => $winsBest,
            'form' => array_slice($outcomes, -5),
        ];
    }

    /**
     * Historia startów (tabele końcowe zakończonych sezonów) i rekordy osobiste ze wszystkich meczów.
     *
     * @return array{competitions: array<int, array<string, mixed>>, records: array<string, mixed>}
     */
    public static function history(User $user): array
    {
        $standings = FinalStanding::with('competition', 'season:id,number')->where('user_id', $user->id)->get();

        $groups = $standings->groupBy(fn($s) => $s->competition->type->value . '-' . ($s->competition->tier ?? 0));
        $competitions = [];

        foreach ($groups as $rows) {
            $competition = $rows->first()->competition;
            $label = $competition->type === CompetitionType::League
                ? (League::tryFrom((int) $competition->tier)?->label() ?? $competition->name)
                : $competition->type->label();

            $competitions[] = [
                'label' => $label,
                'type' => $competition->type,
                'tier' => $competition->tier ?? 0,
                'seasons' => $rows->count(),
                'best' => (int) $rows->min('place'),
                'titles' => $rows->where('place', 1)->count(),
                'points' => (int) $rows->sum(fn($s) => $s->stats['points'] ?? 0),
                'won' => (int) $rows->sum(fn($s) => $s->stats['won'] ?? 0),
                'goals' => (int) $rows->sum(fn($s) => $s->stats['for'] ?? 0),
            ];
        }

        $order = array_map(fn($t) => $t->value, CompetitionType::cases());
        usort($competitions, fn($a, $b) => [array_search($a['type']->value, $order, true), $a['tier']] <=> [array_search($b['type']->value, $order, true), $b['tier']]);

        $teamIds = SeasonTeam::where('user_id', $user->id)->pluck('id');

        return ['competitions' => $competitions, 'records' => self::matches($teamIds)];
    }

    /* ==================================================================
     | POMOCNICZE
     * ================================================================*/

    private static array $teams = [];

    private static function team(User $user, Season $season): ?SeasonTeam
    {
        return self::$teams[$user->id . '-' . $season->id] ??= SeasonTeam::where('season_id', $season->id)->where('user_id', $user->id)->first();
    }

    /** Jeden wiersz rozliczenia na kolejkę (dane typu są takie same we wszystkich zestawach), z numerem kolejki. */
    private static function tipRows(User $user, Season $season): Collection
    {
        $team = self::team($user, $season);

        if (!$team) {
            return collect();
        }

        return TeamScore::query()
            ->join('matchdays', 'matchdays.id', '=', 'team_scores.matchday_id')
            ->where('team_scores.season_team_id', $team->id)
            ->select('team_scores.*', 'matchdays.number')
            ->get()
            ->unique('matchday_id')
            ->values();
    }

    /**
     * Mecze zespołów ze wszystkich sezonów (rekordy osobiste).
     *
     * @param  Collection<int, int>  $teamIds
     * @return array<string, mixed>
     */
    private static function matches(Collection $teamIds): array
    {
        $entries = CompetitionEntry::whereIn('season_team_id', $teamIds)->pluck('id');
        $fixtures = Fixture::query()
            ->join('competitions', 'competitions.id', '=', 'fixtures.competition_id')
            ->join('seasons', 'seasons.id', '=', 'competitions.season_id')
            ->where(fn($q) => $q->whereIn('home_entry_id', $entries)->orWhereIn('away_entry_id', $entries))
            ->whereNotNull('home_goals')
            ->orderBy('seasons.number')->orderBy('fixtures.round')->orderBy('fixtures.id')
            ->select('fixtures.*')
            ->get();

        $results = [];
        foreach ($fixtures as $fixture) {
            $home = $entries->contains($fixture->home_entry_id);
            $results[] = self::result($fixture, $home ? $fixture->home_entry_id : $fixture->away_entry_id, $home);
        }

        return self::matchSummary($results);
    }

    /** Obecna i najdłuższa seria wartości true. @param array<int, bool> $values @return array{0: int, 1: int} */
    private static function streak(array $values): array
    {
        $current = $best = 0;
        foreach ($values as $value) {
            $current = $value ? $current + 1 : 0;
            $best = max($best, $current);
        }

        return [$current, $best];
    }
}
