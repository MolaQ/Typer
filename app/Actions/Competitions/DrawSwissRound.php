<?php

namespace App\Actions\Competitions;

use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Fixture;
use App\Support\Audit;
use App\Support\SwissPairing;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Losuje jedną rundę Ligi podwórkowej systemem szwajcarskim.
 *  - Runda 1: pary z listy przedsezonowej (1-2, 3-4, 5-6...).
 *  - Rundy 2-9: pary według aktualnej klasyfikacji (App\Support\Standings, po wynikach poprzedniej rundy),
 *    więc kolejność musi przyjść z zewnątrz w $rankedEntryIds (id wpisów od najlepszego).
 *  - Nieparzysta liczba zespołów: wolny los dostaje najgorszy zespół, który go jeszcze nie miał.
 *    Gra wtedy z wirtualnym rywalem (mecz bez gościa), zgodnie z regulaminem.
 * Rundę losuje się raz, po poprzedniej, w kolejności 1, 2, 3...
 */
class DrawSwissRound
{
    public const ROUNDS = 9;

    /**
     * @param  array<int, int>|null  $rankedEntryIds
     * @return int liczba utworzonych meczów
     *
     * @throws DomainException
     */
    public function handle(Competition $competition, int $round, ?array $rankedEntryIds = null): int
    {
        if ($competition->type !== CompetitionType::Swiss) {
            throw new DomainException(__('This competition does not use the Swiss system.'));
        }

        if ($round < 1 || $round > self::ROUNDS) {
            throw new DomainException(__('Wrong round number.'));
        }

        return DB::transaction(function () use ($competition, $round, $rankedEntryIds): int {
            $fixtures = Fixture::where('competition_id', $competition->id)->get();

            if ($fixtures->where('round', $round)->isNotEmpty()) {
                throw new DomainException(__('This round has already been drawn.'));
            }

            if ($round > 1 && $fixtures->where('round', $round - 1)->isEmpty()) {
                throw new DomainException(__('Draw the previous round first.'));
            }

            if ($round > 1 && $rankedEntryIds === null) {
                throw new DomainException(__('The next rounds are drawn from the standings, which need match results (a later stage).'));
            }

            $entries = CompetitionEntry::where('competition_id', $competition->id)->orderBy('seed')->get();

            if ($entries->count() < 2) {
                throw new DomainException(__('There are not enough teams to draw a round.'));
            }

            $ranked = $rankedEntryIds ?? $entries->pluck('id')->all();

            $played = [];
            $hadBye = [];

            foreach ($fixtures as $fixture) {
                if ($fixture->away_entry_id === null) {
                    $hadBye[$fixture->home_entry_id] = true;
                } else {
                    $played[SwissPairing::key($fixture->home_entry_id, $fixture->away_entry_id)] = true;
                }
            }

            $result = SwissPairing::pair($ranked, $played, $hadBye);
            $seeds = $entries->pluck('seed', 'id');
            $now = now();
            $rows = [];

            foreach ($result['pairs'] as [$home, $away]) {
                $rows[] = [
                    'competition_id' => $competition->id,
                    'round' => $round,
                    'home_seat' => $seeds[$home],
                    'away_seat' => $seeds[$away],
                    'home_entry_id' => $home,
                    'away_entry_id' => $away,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($result['bye'] !== null) {
                $rows[] = [
                    'competition_id' => $competition->id,
                    'round' => $round,
                    'home_seat' => $seeds[$result['bye']],
                    'away_seat' => null,
                    'home_entry_id' => $result['bye'],
                    'away_entry_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('fixtures')->insert($chunk);
            }

            Audit::log(
                'competition.drawn',
                null,
                [],
                ['round' => $round, 'matches' => count($rows)],
                $competition->name.' ('.$competition->season->title.')',
            );

            return count($rows);
        });
    }
}
