<?php

namespace App\Actions\Competitions;

use App\Enums\CompetitionType;
use App\Enums\League;
use App\Enums\SeasonStatus;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\FinalStanding;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Support\CupBracket;
use App\Support\GoldenLeague;
use App\Support\LeagueSchedule;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Tworzy rozgrywki automatyczne sezonu na podstawie listy przedsezonowej:
 *  - 10 lig (po 10 zespołów, terminarz z LeagueSchedule, 9 kolejek),
 *  - Puchar Polski (pierwsze 512 miejsc listy, cała drabinka 511 meczów z CupBracket),
 *  - Ligę podwórkową (miejsca od 101, system szwajcarski: pary losuje się rundami),
 *  - Ligę Legend (wszystkie zespoły ludzi),
 *  - ligi europejskie z tabel końcowych poprzedniego sezonu (gdy taki jest),
 *  - Złotą Ligę z wpłat z ostatnich 12 miesięcy (gdy ktoś z listy wspierał, GoldenLeague).
 * Można wywołać wielokrotnie: rozgrywki, które już istnieją, są pomijane (przycisk
 * "Wygeneruj brakujące rozgrywki" na stronie Terminarz).
 *
 * @return array{leagues: int, league_fixtures: int, cup_fixtures: int, swiss_entries: int, legends_entries: int, european: int, golden: int}
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

            $stats = ['leagues' => 0, 'league_fixtures' => 0, 'cup_fixtures' => 0, 'swiss_entries' => 0, 'legends_entries' => 0, 'european' => 0, 'golden' => 0];

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

            // --- Liga Legend: wszystkie zespoły ludzi (bez botów), eliminacja po kolejkach ---
            if (! $this->exists($season, CompetitionType::Legends)) {
                $competition = Competition::create([
                    'season_id' => $season->id,
                    'type' => CompetitionType::Legends,
                    'tier' => null,
                    'name' => CompetitionType::Legends->label(),
                ]);

                $humans = SeasonTeam::where('season_id', $season->id)->whereNotNull('user_id')->orderBy('position')->pluck('id');
                $seatTeams = [];
                foreach ($humans->values() as $index => $teamId) {
                    $seatTeams[$index + 1] = $teamId;
                }

                $this->createEntries($competition, $seatTeams);
                $stats['legends_entries'] = count($seatTeams);
            }

            // --- Ligi europejskie z tabel końcowych poprzedniego sezonu ---
            $stats['european'] = $this->buildEuropean($season);

            // --- Złota Liga z wpłat ---
            $stats['golden'] = $this->buildGolden($season);

            return $stats;
        });
    }

    /**
     * Liga Mistrzów, Europy i Konferencji: miejsca 1, 2 i 3 lig 1-10 z poprzedniego zakończonego sezonu,
     * rozstawione według poziomu ligi (mistrz Ekstraklasy = 1). Bez poprzedniego sezonu nic nie robi
     * (w sezonie 1 admin tworzy je ręcznie). Rozgrywki, które już istnieją, są pomijane.
     * Wywoływane też ze strony Rozgrywki (przycisk „Utwórz z historii”).
     */
    public function buildEuropean(Season $season): int
    {
        $created = 0;

        foreach ([1, 2, 3] as $place) {
            $type = CompetitionType::europeanFor($place);

            if ($this->exists($season, $type)) {
                continue;
            }

            $seatTeams = $this->europeanSeats($season, $type);

            if ($seatTeams === []) {
                continue;
            }

            $competition = Competition::create([
                'season_id' => $season->id,
                'type' => $type,
                'tier' => null,
                'name' => $type->label(),
            ]);

            $this->fillRoundRobin($competition, $seatTeams);
            $created++;
        }

        return $created;
    }

    /** Złota Liga ze składem z wpłat (GoldenLeague). Pomijana, gdy już istnieje albo nikt z listy nie wspierał. */
    public function buildGolden(Season $season): int
    {
        if ($this->exists($season, CompetitionType::Golden)) {
            return 0;
        }

        $seatTeams = GoldenLeague::seats($season);

        if ($seatTeams === []) {
            return 0;
        }

        $competition = Competition::create([
            'season_id' => $season->id,
            'type' => CompetitionType::Golden,
            'tier' => null,
            'name' => CompetitionType::Golden->label(),
        ]);

        $this->fillRoundRobin($competition, $seatTeams);

        return 1;
    }

    /** Brakujące rozgrywki z podstawą w historii: ligi europejskie i Złota Liga (strona Rozgrywki). */
    public function buildFromHistory(Season $season): int
    {
        return DB::transaction(fn (): int => $this->buildEuropean($season) + $this->buildGolden($season));
    }

    /**
     * Skład z historii dla rozgrywek ręcznych: ligi europejskie z tabel poprzedniego sezonu, Złota Liga z wpłat.
     *
     * @return array<int, int> seed => id zespołu z listy
     */
    public function historySeats(Season $season, CompetitionType $type): array
    {
        return $type === CompetitionType::Golden ? GoldenLeague::seats($season) : $this->europeanSeats($season, $type);
    }

    /**
     * Uzupełnia puste rozgrywki ręczne składem z historii (np. utworzone, zanim był poprzedni sezon albo wpłaty).
     * Zwraca liczbę dodanych zespołów.
     */
    public function fillFromHistory(Competition $competition): int
    {
        if (! $competition->type->isManual() || $competition->entries()->exists()) {
            return 0;
        }

        return DB::transaction(fn (): int => $this->fillRoundRobin($competition, $this->historySeats($competition->season, $competition->type)));
    }

    /**
     * Skład ligi europejskiej (seed => id zespołu z listy sezonu): to samo miejsce (1, 2 albo 3) we wszystkich
     * ligach 1-10 poprzedniego zakończonego sezonu, według poziomu ligi. Pusta tablica, gdy nie ma historii.
     *
     * @return array<int, int>
     */
    public function europeanSeats(Season $season, CompetitionType $type): array
    {
        $place = match ($type) {
            CompetitionType::Champions => 1,
            CompetitionType::Europa => 2,
            CompetitionType::Conference => 3,
            default => null,
        };

        $previous = $this->previousSeason($season);

        if ($place === null || ! $previous) {
            return [];
        }

        $leagues = Competition::where('season_id', $previous->id)->where('type', CompetitionType::League->value)->pluck('tier', 'id');
        $rows = FinalStanding::whereIn('competition_id', $leagues->keys())->where('place', $place)->get();
        $teams = SeasonTeam::where('season_id', $season->id)->get(['id', 'user_id', 'bot_id', 'previous_id']);

        $seatTeams = [];
        foreach ($rows->sortBy(fn ($row) => $leagues[$row->competition_id]) as $row) {
            // To samo miejsce w nowym sezonie: przez previous_id, a gdy listę zbudowano ręcznie, po graczu lub bocie.
            $team = $teams->firstWhere('previous_id', $row->season_team_id)
                ?? ($row->user_id ? $teams->firstWhere('user_id', $row->user_id) : $teams->firstWhere('bot_id', $row->bot_id));

            if ($team && ! in_array($team->id, $seatTeams, true)) {
                $seatTeams[count($seatTeams) + 1] = $team->id;
            }
        }

        return $seatTeams;
    }

    /** Ostatni zakończony sezon przed danym (podstawa składów z historii). */
    public function previousSeason(Season $season): ?Season
    {
        return Season::where('status', SeasonStatus::Finished->value)
            ->where('number', '<', $season->number)
            ->orderByDesc('number')
            ->first();
    }

    /**
     * Dodaje uczestników; terminarz tylko przy komplecie 10 zespołów (inaczej admin uzupełnia skład na stronie Rozgrywki).
     *
     * @param  array<int, int>  $seatTeams
     */
    private function fillRoundRobin(Competition $competition, array $seatTeams): int
    {
        if ($seatTeams === []) {
            return 0;
        }

        $entries = $this->createEntries($competition, $seatTeams);

        if (count($entries) === League::SIZE) {
            $rows = [];
            foreach (LeagueSchedule::fixtures() as $match) {
                $rows[] = $this->fixtureRow($competition, $match['round'], $match['home'], $match['away'], $entries);
            }
            $this->insertFixtures($rows);
        }

        return count($entries);
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
