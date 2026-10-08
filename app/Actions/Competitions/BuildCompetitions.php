<?php

namespace App\Actions\Competitions;

use App\Enums\CompetitionType;
use App\Enums\League;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Support\CupBracket;
use App\Support\LeagueSchedule;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Tworzy rozgrywki automatyczne sezonu na podstawie listy przedsezonowej:
 *  - 10 lig (po 10 zespołów, terminarz z LeagueSchedule, 9 kolejek),
 *  - Puchar Polski (pierwsze 512 miejsc listy, cała drabinka 511 meczów z CupBracket),
 *  - Ligę podwórkową (miejsca od 101, system szwajcarski: pary losuje się rundami).
 * Można wywołać wielokrotnie: rozgrywki, które już istnieją, są pomijane (przycisk
 * "Wygeneruj brakujące rozgrywki" na stronie Terminarz).
 *
 * @return array{leagues: int, league_fixtures: int, cup_fixtures: int, swiss_entries: int}
 */
class BuildCompetitions
{
    public function handle(Season $season): array
    {
        return DB::transaction(function () use ($season): array {
            $teams = SeasonTeam::where('season_id', $season->id)->orderBy('position')->get(['id', 'position'])->keyBy('position');

            if ($teams->count() < SeasonTeam::CUP_SIZE) {
                throw new DomainException(__('Not enough bots. Run: php artisan db:seed --class=BotsSeeder'));
            }

            $stats = ['leagues' => 0, 'league_fixtures' => 0, 'cup_fixtures' => 0, 'swiss_entries' => 0];

            // --- 10 lig ---
            foreach (League::cases() as $league) {
                if (! $league->isTop() || $this->exists($season, CompetitionType::League, $league->value)) {
                    continue;
                }

                $competition = Competition::create([
                    'season_id' => $season->id,
                    'type' => CompetitionType::League,
                    'tier' => $league->value,
                    'name' => $league->label(),
                ]);

                $seatTeams = [];
                for ($seed = 1; $seed <= League::SIZE; $seed++) {
                    $seatTeams[$seed] = $teams->get($league->firstPosition() + $seed - 1)->id;
                }

                $entries = $this->createEntries($competition, $seatTeams);

                $rows = [];
                foreach (LeagueSchedule::fixtures() as $match) {
                    $rows[] = $this->fixtureRow($competition, $match['round'], $match['home'], $match['away'], $entries);
                }

                $this->insertFixtures($rows);

                $stats['leagues']++;
                $stats['league_fixtures'] += count($rows);
            }

            // --- Puchar Polski ---
            if (! $this->exists($season, CompetitionType::Cup)) {
                $competition = Competition::create([
                    'season_id' => $season->id,
                    'type' => CompetitionType::Cup,
                    'tier' => null,
                    'name' => CompetitionType::Cup->label(),
                ]);

                $seatTeams = [];
                for ($seed = 1; $seed <= CupBracket::TEAMS; $seed++) {
                    $seatTeams[$seed] = $teams->get($seed)->id;
                }

                $entries = $this->createEntries($competition, $seatTeams);

                $rows = [];
                foreach (CupBracket::fixtures() as $match) {
                    // Zespoły znamy tylko w rundzie 1, dalej obowiązują same numery miejsc.
                    $known = $match['round'] === 1;
                    $rows[] = $this->fixtureRow($competition, $match['round'], $match['home'], $match['away'], $known ? $entries : []);
                }

                $this->insertFixtures($rows);

                $stats['cup_fixtures'] = count($rows);
            }

            // --- Liga podwórkowa (bez terminarza, pary losuje DrawSwissRound) ---
            if (! $this->exists($season, CompetitionType::Swiss, League::Podworkowa->value)) {
                $competition = Competition::create([
                    'season_id' => $season->id,
                    'type' => CompetitionType::Swiss,
                    'tier' => League::Podworkowa->value,
                    'name' => CompetitionType::Swiss->label(),
                ]);

                $seatTeams = [];
                foreach ($teams as $position => $team) {
                    if ($position > League::TOP_TEAMS) {
                        $seatTeams[$position - League::TOP_TEAMS] = $team->id;
                    }
                }

                $this->createEntries($competition, $seatTeams);
                $stats['swiss_entries'] = count($seatTeams);
            }

            return $stats;
        });
    }

    private function exists(Season $season, CompetitionType $type, ?int $tier = null): bool
    {
        return Competition::where('season_id', $season->id)
            ->where('type', $type->value)
            ->when($tier !== null, fn ($q) => $q->where('tier', $tier))
            ->exists();
    }

    /**
     * Wstawia uczestników (seed => id zespołu z listy) hurtowo i zwraca mapę seed => id wpisu.
     *
     * @param  array<int, int>  $seatTeams
     * @return array<int, int>
     */
    private function createEntries(Competition $competition, array $seatTeams): array
    {
        $now = now();
        $rows = [];

        foreach ($seatTeams as $seed => $seasonTeamId) {
            $rows[] = [
                'competition_id' => $competition->id,
                'season_team_id' => $seasonTeamId,
                'seed' => $seed,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            CompetitionEntry::insert($chunk);
        }

        return CompetitionEntry::where('competition_id', $competition->id)->pluck('id', 'seed')->all();
    }

    /** @param  array<int, int>  $entries  seed => id wpisu (puste = zespoły jeszcze nieznane) */
    private function fixtureRow(Competition $competition, int $round, int $home, int $away, array $entries): array
    {
        $now = now();

        return [
            'competition_id' => $competition->id,
            'round' => $round,
            'home_seat' => $home,
            'away_seat' => $away,
            'home_entry_id' => $entries[$home] ?? null,
            'away_entry_id' => $entries[$away] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function insertFixtures(array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('fixtures')->insert($chunk);
        }
    }
}
