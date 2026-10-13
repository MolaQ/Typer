<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Enums\League;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Models\SeasonTeam;
use App\Models\TeamScore;
use Illuminate\Support\Collection;

/**
 * Tabela rozgrywek ligowych (ligi, podwórkowa, grupy pucharów europejskich) z rozegranych meczów.
 * Kryteria (regulamin, punkt 4): punkty, bilans goli, bramki strzelone, wygrane, remisy,
 * dokładne typy, trafione różnice, trafione rozstrzygnięcia, bonusy, punkty Hall of Fame
 * (stan na początku sezonu, HallOfFame::pointsBefore), a na końcu pozycja na liście przedsezonowej.
 * Dokładne typy i bonusy liczymy z kolejek, w których zespół rozegrał mecz w tych rozgrywkach.
 */
final class Standings
{
    /**
     * Puchary europejskie: $entryIds zawęża tabelę do jednej grupy, a $maxRound do meczów fazy grupowej.
     *
     * @param  array<int, int>|null  $entryIds
     * @return Collection<int, array{entry_id: int, team: SeasonTeam, played: int, won: int, drawn: int, lost: int, for: int, against: int, diff: int, points: int, exact: int, diff_hits: int, outcome_hits: int, bonus: int, hof: int}>
     */
    public static function for(Competition $competition, ?array $entryIds = null, ?int $maxRound = null): Collection
    {
        $entries = $competition->entries()
            ->when($entryIds !== null, fn ($q) => $q->whereIn('id', $entryIds))
            ->with(['seasonTeam.user:id,name,team_name', 'seasonTeam.bot:id,name'])
            ->get();

        $season = $competition->season;
        $rows = [];
        foreach ($entries as $entry) {
            $rows[$entry->id] = [
                'entry_id' => $entry->id,
                'team' => $entry->seasonTeam,
                'played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0,
                'for' => 0, 'against' => 0, 'diff' => 0, 'points' => 0,
                'exact' => 0, 'diff_hits' => 0, 'outcome_hits' => 0, 'bonus' => 0,
                'hof' => $season ? HallOfFame::teamPointsBefore($season, $entry->seasonTeam?->user_id, $entry->seasonTeam?->bot_id) : 0,
            ];
        }

        $fixtures = Fixture::where('competition_id', $competition->id)
            ->whereNotNull('home_goals')
            ->when($maxRound !== null, fn ($q) => $q->where('round', '<=', $maxRound))
            ->get();

        if ($fixtures->isEmpty()) {
            return self::sort($rows);
        }

        $matchdayIds = Matchday::where('season_id', $competition->season_id)->pluck('id', 'number');
        $teamOfEntry = $entries->pluck('season_team_id', 'id');

        $scores = TeamScore::whereIn('matchday_id', $matchdayIds->values())
            ->whereIn('season_team_id', $teamOfEntry->values())
            ->where('score_set', $competition->type->scoreSet()->value)
            ->get()
            ->groupBy(fn ($s) => $s->matchday_id.'-'.$s->season_team_id);

        foreach ($fixtures as $fixture) {
            $sides = [[$fixture->home_entry_id, $fixture->home_goals, $fixture->away_goals]];

            if ($fixture->away_entry_id !== null) {
                $sides[] = [$fixture->away_entry_id, $fixture->away_goals, $fixture->home_goals];
            }

            foreach ($sides as [$entryId, $for, $against]) {
                if (! isset($rows[$entryId])) {
                    continue;
                }

                $row = &$rows[$entryId];
                $row['played']++;
                $row['for'] += $for;
                $row['against'] += $against;
                $row['points'] += Scoring::matchPoints($for, $against);
                $row[$for > $against ? 'won' : ($for === $against ? 'drawn' : 'lost')]++;

                $score = $scores->get(($matchdayIds[$fixture->round] ?? 0).'-'.$teamOfEntry[$entryId])?->first();
                if ($score) {
                    $row['exact'] += (int) $score->exact_hit;
                    $row['diff_hits'] += (int) $score->diff_hit;
                    $row['outcome_hits'] += (int) $score->outcome_hit;
                    $row['bonus'] += $score->bonus();
                }
                unset($row);
            }
        }

        foreach ($rows as &$row) {
            $row['diff'] = $row['for'] - $row['against'];
        }
        unset($row);

        return self::sort($rows);
    }

    /**
     * Strefa miejsca w tabeli do pokolorowania: 'up' (awans), 'down' (spadek) albo null.
     * Ligi 2-10 i podwórkowa: 4 najlepsze awansują; ligi 1-10: 4 najgorsze spadają (regulamin, punkt 9).
     */
    public static function zone(Competition $competition, int $place, int $count): ?string
    {
        $tier = $competition->type === CompetitionType::Swiss ? League::Podworkowa->value : $competition->tier;

        if (! in_array($competition->type, [CompetitionType::League, CompetitionType::Swiss], true) || $tier === null) {
            return null;
        }

        if ($tier > 1 && $place <= Promotion::MOVES) {
            return 'up';
        }

        if ($tier < League::Podworkowa->value && $place > $count - Promotion::MOVES) {
            return 'down';
        }

        return null;
    }

    /** @var array<int, array<int, string>> */
    private static array $seasonMoves = [];

    /**
     * Awanse i spadki po ostatniej kolejce sezonu (regulamin, punkt 9), razem z zamianami z czyszczenia z botów:
     * id miejsca na liście (season_teams) => 'up' albo 'down'. Pusta tablica, dopóki ostatnia kolejka
     * lig nie ma wyników (w trakcie sezonu nie pokazujemy prognozy, tylko stałe strefy z zone()).
     *
     * @return array<int, string>
     */
    public static function seasonMoves(int $seasonId): array
    {
        if (isset(self::$seasonMoves[$seasonId])) {
            return self::$seasonMoves[$seasonId];
        }

        $competitions = Competition::where('season_id', $seasonId)
            ->whereIn('type', [CompetitionType::League->value, CompetitionType::Swiss->value])
            ->get();

        $fixtures = Fixture::whereIn('competition_id', $competitions->pluck('id'));
        $lastRound = (clone $fixtures)->max('round');
        $finished = $lastRound !== null
            && ! (clone $fixtures)->where('round', $lastRound)->whereNull('home_goals')->exists();

        if (! $finished) {
            return self::$seasonMoves[$seasonId] = [];
        }

        $eligible = Players::eligible()->pluck('id')->flip();
        $tiers = [];
        foreach ($competitions as $competition) {
            $level = $competition->type === CompetitionType::Swiss ? League::Podworkowa->value : (int) $competition->tier;
            foreach (self::for($competition) as $row) {
                $userId = $row['team']->user_id;
                $tiers[$level][] = ['id' => $row['team']->id, 'human' => $userId !== null && isset($eligible[$userId])];
            }
        }

        return self::$seasonMoves[$seasonId] = $tiers === [] ? [] : Promotion::moves($tiers);
    }

    /**
     * Id wpisów od najlepszego (klasyfikacja do losowania kolejnej rundy Ligi podwórkowej).
     *
     * @return array<int, int>
     */
    public static function rankedEntryIds(Competition $competition): array
    {
        return self::for($competition)->pluck('entry_id')->all();
    }

    /**
     * Ranking wierszy tabeli kryteriami ligowymi (np. wirtualna tabela zespołów z tego samego miejsca w grupach).
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public static function rank(array $rows): Collection
    {
        return self::sort($rows);
    }

    private static function sort(array $rows): Collection
    {
        return collect($rows)->sort(function (array $a, array $b) {
            foreach (['points', 'diff', 'for', 'won', 'drawn', 'exact', 'diff_hits', 'outcome_hits', 'bonus', 'hof'] as $key) {
                if ($a[$key] !== $b[$key]) {
                    return $b[$key] <=> $a[$key];
                }
            }

            return $a['team']->position <=> $b['team']->position;
        })->values();
    }
}
