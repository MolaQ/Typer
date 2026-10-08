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
     * Statystyki sezonu.
     *
     * @return array<string, mixed>
     */
    public static function season(User $user, Season $season): array
    {
        $rows = self::tipRows($user, $season);
        $tipped = $rows->where('has_tip', true);
        $tips = $tipped->count();
        $all = self::team($user, $season)
            ? TeamScore::where('season_team_id', self::team($user, $season)->id)->where('has_tip', true)->get()
            : collect();

        [$currentHits, $bestHits] = self::streak($rows->sortBy('number')->map(fn($r) => (bool) $r->outcome_hit)->values()->all());
        $matches = self::matches(self::team($user, $season) ? collect([self::team($user, $season)->id]) : collect());

        return [
            'scored' => $rows->count(),
            'tips' => $tips,
            'missing' => $rows->count() - $tips,
            'exact' => $tipped->where('exact_hit', true)->count(),
            'diff' => $tipped->where('diff_hit', true)->count(),
            'outcome' => $tipped->where('outcome_hit', true)->count(),
            'accuracy' => $tips > 0 ? round($tipped->where('outcome_hit', true)->count() / $tips * 100) : null,
            'tip_points' => (int) $tipped->sum('tip_points'),
            'avg_tip' => $tips > 0 ? round($tipped->avg('tip_points'), 2) : null,
            'avg_offense' => $all->isNotEmpty() ? round($all->avg('offense_bonus'), 2) : null,
            'avg_defense' => $all->isNotEmpty() ? round($all->avg('defense_bonus'), 2) : null,
            'zeroed' => $all->where('offense_zeroed', true)->count() + $all->where('defense_zeroed', true)->count(),
            'outcome_streak' => $currentHits,
            'outcome_streak_best' => $bestHits,
            'matches' => $matches,
            'rank' => self::rank($user, $season),
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
     * Mecze zespołów (W/R/P, bramki, rekord bramek, passa bez porażki). Remis rozstrzygnięty czasem typu
     * w pucharze liczymy jako wygraną albo porażkę.
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

        $won = $drawn = $lost = $for = $against = $record = 0;
        $results = [];

        foreach ($fixtures as $fixture) {
            $home = $entries->contains($fixture->home_entry_id);
            $mine = (int) ($home ? $fixture->home_goals : $fixture->away_goals);
            $theirs = (int) ($home ? $fixture->away_goals : $fixture->home_goals);
            $for += $mine;
            $against += $theirs;
            $record = max($record, $mine);

            if ($mine === $theirs && $fixture->winner_entry_id) {
                $result = $entries->contains($fixture->winner_entry_id) ? 'W' : 'L';
            } else {
                $result = $mine > $theirs ? 'W' : ($mine === $theirs ? 'D' : 'L');
            }

            $results[] = $result;
            $result === 'W' ? $won++ : ($result === 'D' ? $drawn++ : $lost++);
        }

        [$unbeaten, $unbeatenBest] = self::streak(array_map(fn($r) => $r !== 'L', $results));
        [$wins, $winsBest] = self::streak(array_map(fn($r) => $r === 'W', $results));

        return [
            'played' => count($results),
            'won' => $won, 'drawn' => $drawn, 'lost' => $lost,
            'for' => $for, 'against' => $against,
            'record_goals' => $record,
            'unbeaten' => $unbeaten, 'unbeaten_best' => $unbeatenBest,
            'wins' => $wins, 'wins_best' => $winsBest,
            'form' => array_slice($results, -5),
        ];
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

    /**
     * Miejsce wśród graczy sezonu według punktów za typy (i mediana). Null, gdy nie ma rozliczonych kolejek.
     *
     * @return array{place: int, count: int, median: float, above: bool}|null
     */
    private static function rank(User $user, Season $season): ?array
    {
        $team = self::team($user, $season);

        $totals = TeamScore::query()
            ->join('season_teams', 'season_teams.id', '=', 'team_scores.season_team_id')
            ->where('season_teams.season_id', $season->id)
            ->whereNotNull('season_teams.user_id')
            ->selectRaw('team_scores.season_team_id, team_scores.matchday_id, max(team_scores.tip_points) as points')
            ->groupBy('team_scores.season_team_id', 'team_scores.matchday_id')
            ->get()
            ->groupBy('season_team_id')
            ->map(fn($rows) => (int) $rows->sum('points'));

        if (!$team || $totals->isEmpty() || !$totals->has($team->id)) {
            return null;
        }

        $mine = $totals[$team->id];
        $sorted = $totals->sort()->values();
        $count = $sorted->count();
        $median = $count % 2 ? (float) $sorted[intdiv($count, 2)] : ($sorted[$count / 2 - 1] + $sorted[$count / 2]) / 2;

        return [
            'place' => $totals->filter(fn($points) => $points > $mine)->count() + 1,
            'count' => $count,
            'median' => $median,
            'above' => $mine >= $median,
        ];
    }
}
