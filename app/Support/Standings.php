<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Enums\League;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Models\TeamScore;
use Illuminate\Support\Collection;

/**
 * Tabela rozgrywek ligowych (ligi, podwórkowa, ligi europejskie, Złota Liga) z rozegranych meczów.
 * Kryteria (regulamin, punkt 4): punkty, bilans goli, bramki strzelone, wygrane, remisy,
 * dokładne typy, trafione różnice, trafione rozstrzygnięcia, bonusy, punkty Hall of Fame
 * (etap 14, na razie 0), a na końcu pozycja na liście przedsezonowej.
 * Dokładne typy i bonusy liczymy z kolejek, w których zespół rozegrał mecz w tych rozgrywkach.
 */
final class Standings
{
    /**
     * @return Collection<int, array{entry_id: int, team: \App\Models\SeasonTeam, played: int, won: int, drawn: int, lost: int, for: int, against: int, diff: int, points: int, exact: int, diff_hits: int, outcome_hits: int, bonus: int, hof: int}>
     */
    public static function for(Competition $competition): Collection
    {
        $entries = $competition->entries()->with(['seasonTeam.user:id,name,team_name', 'seasonTeam.bot:id,name'])->get();

        $rows = [];
        foreach ($entries as $entry) {
            $rows[$entry->id] = [
                'entry_id' => $entry->id,
                'team' => $entry->seasonTeam,
                'played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0,
                'for' => 0, 'against' => 0, 'diff' => 0, 'points' => 0,
                'exact' => 0, 'diff_hits' => 0, 'outcome_hits' => 0, 'bonus' => 0, 'hof' => 0,
            ];
        }

        $fixtures = Fixture::where('competition_id', $competition->id)->whereNotNull('home_goals')->get();

        if ($fixtures->isEmpty()) {
            return self::sort($rows);
        }

        $matchdayIds = Matchday::where('season_id', $competition->season_id)->pluck('id', 'number');
        $teamOfEntry = $entries->pluck('season_team_id', 'id');

        $scores = TeamScore::whereIn('matchday_id', $matchdayIds->values())
            ->whereIn('season_team_id', $teamOfEntry->values())
            ->where('question_set', $competition->type->questionSet()->value)
            ->get()
            ->groupBy(fn($s) => $s->matchday_id . '-' . $s->season_team_id);

        foreach ($fixtures as $fixture) {
            $sides = [[$fixture->home_entry_id, $fixture->home_goals, $fixture->away_goals]];

            if ($fixture->away_entry_id !== null) {
                $sides[] = [$fixture->away_entry_id, $fixture->away_goals, $fixture->home_goals];
            }

            foreach ($sides as [$entryId, $for, $against]) {
                if (!isset($rows[$entryId])) {
                    continue;
                }

                $row = &$rows[$entryId];
                $row['played']++;
                $row['for'] += $for;
                $row['against'] += $against;
                $row['points'] += Scoring::matchPoints($for, $against);
                $row[$for > $against ? 'won' : ($for === $against ? 'drawn' : 'lost')]++;

                $score = $scores->get(($matchdayIds[$fixture->round] ?? 0) . '-' . $teamOfEntry[$entryId])?->first();
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

        if (!in_array($competition->type, [CompetitionType::League, CompetitionType::Swiss], true) || $tier === null) {
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

    /** Ligi 1-10: miejsca 1-3 dają start w Lidze Mistrzów, Europy i Konferencji następnego sezonu. */
    public static function europeanBadge(Competition $competition, int $place): ?string
    {
        if ($competition->type !== CompetitionType::League) {
            return null;
        }

        return match ($place) {
            1 => 'LM',
            2 => 'LE',
            3 => 'LK',
            default => null,
        };
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
