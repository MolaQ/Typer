<?php

namespace App\Actions\Seasons;

use App\Enums\CompetitionType;
use App\Enums\League;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\FinalStanding;
use App\Models\Fixture;
use App\Models\HallOfFameAward;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\TeamScore;
use App\Support\CupBracket;
use App\Support\EuropeanCup;
use App\Support\HallOfFame;
use App\Support\Ownership;
use App\Support\Promotion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Przyznaje punkty i trofea Hall of Fame za sezon (regulamin, punkt 12). Liczy się na bieżąco po każdym
 * przeliczeniu kolejki (SaveMatchdayResult) i jeszcze raz przy zakończeniu sezonu (FinishSeason).
 * W trakcie sezonu wchodzą tylko punkty za mecze (z terminarza): wygrane i remisy w ligach, mecze grupowe
 * i etapy pucharów europejskich, wygrane rundy pucharu. Miejsca, awanse, król strzelców, Liga Legend, tytuły
 * i trofea dochodzą dopiero z tabel końcowych (final_standings), czyli po zakończeniu sezonu.
 * Przeliczenie kasuje poprzednie wpisy sezonu, więc można je powtarzać (np. po zmianie punktacji w panelu).
 * Punkty bieżącego sezonu nie wpływają na kryterium HoF w tabelach tego sezonu (HallOfFame::pointsBefore).
 *  - ligi i podwórkowa (× mnożnik poziomu): wygrane i remisy, miejsca 1-3, awans, król strzelców,
 *  - Puchar Polski: wygrana w każdej rundzie, zwycięzca finału,
 *  - puchary europejskie: wygrane i remisy w grupie, 1. miejsce w grupie, awans do kolejnych etapów (na bieżąco),
 *    zwycięzca finału (po zakończeniu sezonu),
 *  - Liga Legend: wygrane i remisy, przetrwane kolejki (na bieżąco), miejsca 1-3 tabeli końcowej,
 *  - nagrody indywidualne (po zakończeniu sezonu, gracze i boty): MVP sezonu (najwięcej trafionych rozstrzygnięć,
 *    potem dokładne wyniki, potem różnice), Złota Piłka (najwięcej goli w sezonie ze wszystkich rozgrywek: ofensywa,
 *    czyli punkty za typ + bonus ofensywny, z każdego zestawu pytań zespołu) i Złote Rękawice (najwięcej punktów
 *    z bonusów defensywnych); przy remisie wyższe miejsce w lidze (poziom, potem miejsce w tabeli końcowej).
 * Punkty za mecze i etapy dostaje ten, kto w danej kolejce naprawdę grał miejscem (Ownership): gracz, który wskoczył
 * za bota, nie przejmuje punktów bota. Miejsca, tytuły i trofea z tabel końcowych idą do właściciela na koniec sezonu.
 */
class AwardHallOfFame
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    private Season $season;

    /** Czy sezon ma już tabele końcowe (czyli jest zakończony)? */
    private bool $finished = false;

    /** @return int liczba zapisanych wpisów */
    public function handle(Season $season): int
    {
        $this->season = $season;
        $this->rows = [];
        Ownership::flush(); // świeże rozliczenia kolejki z tego samego żądania

        return DB::transaction(function (): int {
            HallOfFameAward::where('season_id', $this->season->id)->delete();

            $standings = FinalStanding::where('season_id', $this->season->id)->orderBy('place')->get()->groupBy('competition_id');
            $this->finished = $standings->isNotEmpty();

            foreach (Competition::where('season_id', $this->season->id)->get() as $competition) {
                $final = $standings->get($competition->id, collect())->values();

                match ($competition->type) {
                    CompetitionType::League, CompetitionType::Swiss => $this->league($competition, $final),
                    CompetitionType::Champions, CompetitionType::Europa, CompetitionType::Conference => $this->european($competition, $final),
                    CompetitionType::Cup => $this->cup($competition),
                    CompetitionType::Legends => $this->legends($competition, $final),
                };
            }

            $this->individual($standings->all());

            foreach (array_chunk($this->rows, 500) as $chunk) {
                HallOfFameAward::insert($chunk);
            }

            HallOfFame::forget();

            return count($this->rows);
        });
    }

    /** Ligi 1-10 i podwórkowa. */
    private function league(Competition $competition, Collection $final): void
    {
        $tier = $competition->type === CompetitionType::Swiss ? League::Podworkowa->value : (int) $competition->tier;
        $multiplier = HallOfFame::multiplier($tier);

        // Mecze: na bieżąco z terminarza.
        foreach ($this->results($competition) as $result) {
            $points = ($result['won'] * HallOfFame::value('league_win') + $result['drawn'] * HallOfFame::value('league_draw')) * $multiplier;
            $this->addOwner($competition, $result['team'], $result['owner'], 'matches', $points, null, ['won' => $result['won'], 'drawn' => $result['drawn']]);
        }

        if ($final->isEmpty() || $final->max(fn ($s) => $s->stats['played'] ?? 0) === 0) {
            return; // sezon trwa albo rozgrywki bez rozegranych meczów
        }

        $topGoals = (int) $final->max(fn ($s) => $s->stats['for'] ?? 0);
        $topScorerGiven = false;

        foreach ($final as $standing) {
            $placeKey = [1 => 'champion', 2 => 'second', 3 => 'third'][$standing->place] ?? null;
            if ($placeKey) {
                $trophy = $standing->place === 1 ? 'league_'.$tier : null;
                $this->add($competition, $standing, $placeKey, HallOfFame::value('league_'.$placeKey) * $multiplier, $trophy);
            }

            // Awans: 4 najlepsze z lig 2-10 i z podwórkowej (z Ekstraklasy nikt nie awansuje).
            if ($tier > 1 && $standing->place <= Promotion::MOVES) {
                $this->add($competition, $standing, 'promotion', HallOfFame::value('league_promotion') * $multiplier);
            }

            // Król strzelców: najwięcej bramek w lidze; przy równej liczbie wyżej w tabeli.
            if (! $topScorerGiven && $topGoals > 0 && (int) ($standing->stats['for'] ?? 0) === $topGoals) {
                $topScorerGiven = true;
                $this->add($competition, $standing, 'top_scorer', HallOfFame::value('league_top_scorer') * $multiplier, 'top_scorer_'.$tier, ['goals' => $topGoals]);
            }
        }
    }

    /**
     * Liga Mistrzów, Europy i Konferencji (EuropeanCup), na bieżąco:
     *  - wygrane i remisy w fazie grupowej,
     *  - 1. miejsce w grupie (zespoły z miejsc 1-4 drabinki 1/8 finału),
     *  - awans do 1/8 finału, ćwierćfinału, półfinału i finału (narastająco, z obecności w meczu rundy),
     * a po zakończeniu sezonu zwycięzca finału (punkty i trofeum).
     */
    private function european(Competition $competition, Collection $final): void
    {
        $type = $competition->type->value;

        foreach ($this->results($competition, EuropeanCup::GROUP_ROUNDS) as $result) {
            $points = $result['won'] * HallOfFame::value($type.'_win') + $result['drawn'] * HallOfFame::value($type.'_draw');
            $this->addOwner($competition, $result['team'], $result['owner'], 'matches', $points, null, ['won' => $result['won'], 'drawn' => $result['drawn']]);
        }

        $knockout = Fixture::where('competition_id', $competition->id)->where('round', '>', EuropeanCup::GROUP_ROUNDS)->get();

        if ($knockout->isEmpty()) {
            return;
        }

        $teamOfEntry = CompetitionEntry::where('competition_id', $competition->id)->pluck('season_team_id', 'id');
        $teams = SeasonTeam::whereIn('id', $teamOfEntry->values())->get(['id', 'user_id', 'bot_id'])->keyBy('id');
        $rounds = [];
        $groupWinners = [];

        foreach ($knockout as $fixture) {
            foreach ([[$fixture->home_entry_id, $fixture->home_seat], [$fixture->away_entry_id, $fixture->away_seat]] as [$entryId, $seat]) {
                if ($entryId === null) {
                    continue;
                }
                $rounds[$entryId][] = $fixture->round;
                if ($fixture->round === EuropeanCup::GROUP_ROUNDS + 1 && $seat <= count(EuropeanCup::GROUPS)) {
                    $groupWinners[] = $entryId;
                }
            }
        }

        foreach ($rounds as $entryId => $reached) {
            $team = $teams->get($teamOfEntry[$entryId] ?? 0);

            if (! $team) {
                continue;
            }

            // Etap liczy się temu, kto grał miejscem w kolejce tego etapu.
            foreach ($this->byOwner($team->id, $reached) as $owner => $ownerRounds) {
                $points = array_sum(array_map(fn (int $round) => HallOfFame::value($type.'_round_'.$round), $ownerRounds));
                $this->addOwner($competition, $team->id, $owner, 'european_stages', $points, null, ['rounds' => $ownerRounds]);

                if (in_array($entryId, $groupWinners, true) && in_array(EuropeanCup::GROUP_ROUNDS + 1, $ownerRounds, true)) {
                    $this->addOwner($competition, $team->id, $owner, 'group_first', HallOfFame::value($type.'_group_first'));
                }
            }
        }

        // Zwycięzca: punkty i trofeum dopiero po zakończeniu sezonu.
        $finalMatch = $knockout->first(fn (Fixture $f) => $f->round === EuropeanCup::ROUNDS && $f->winner_entry_id !== null);
        if ($this->finished && $finalMatch && ($team = $teams->get($teamOfEntry[$finalMatch->winner_entry_id] ?? 0))) {
            $this->addTeam($competition, $team, 'title', HallOfFame::value($type.'_winner'), $type);
        }
    }

    /**
     * Wygrane i remisy zespołów z rozegranych meczów terminarza. Wolny los w podwórkowej (wirtualny
     * rywal) liczy się jak zwykły mecz, tak jak w tabeli.
     *
     * Wygrane liczą się temu, kto w danej kolejce grał miejscem (Ownership), więc jedno miejsce może dać dwa wiersze.
     *
     * @return list<array{team: int, owner: string, won: int, drawn: int}>
     */
    private function results(Competition $competition, ?int $maxRound = null): array
    {
        $fixtures = Fixture::where('competition_id', $competition->id)
            ->whereNotNull('home_goals')->whereNotNull('away_goals')
            ->when($maxRound !== null, fn ($q) => $q->where('round', '<=', $maxRound))
            ->get(['round', 'home_entry_id', 'away_entry_id', 'home_goals', 'away_goals']);

        $teamOfEntry = CompetitionEntry::where('competition_id', $competition->id)->pluck('season_team_id', 'id');
        $counts = [];
        foreach ($fixtures as $fixture) {
            foreach ([[$fixture->home_entry_id, $fixture->home_goals, $fixture->away_goals], [$fixture->away_entry_id, $fixture->away_goals, $fixture->home_goals]] as [$entryId, $for, $against]) {
                if (! $entryId || ! isset($teamOfEntry[$entryId])) {
                    continue; // wirtualny rywal
                }
                $team = (int) $teamOfEntry[$entryId];
                $owner = Ownership::at($this->season->id, $team, (int) $fixture->round);
                if ($owner === null) {
                    continue;
                }
                $counts[$team.'|'.$owner] ??= ['team' => $team, 'owner' => $owner, 'won' => 0, 'drawn' => 0];
                $counts[$team.'|'.$owner]['won'] += (int) ($for > $against);
                $counts[$team.'|'.$owner]['drawn'] += (int) ($for === $against);
            }
        }

        return array_values($counts);
    }

    /** Puchar Polski: punkty za każdą wygraną rundę (z meczów), zwycięzca finału osobno. */
    private function cup(Competition $competition): void
    {
        $fixtures = Fixture::where('competition_id', $competition->id)->whereNotNull('winner_entry_id')->get(['round', 'winner_entry_id']);

        if ($fixtures->isEmpty()) {
            return;
        }

        $teamOfEntry = CompetitionEntry::where('competition_id', $competition->id)->pluck('season_team_id', 'id');
        $teams = SeasonTeam::whereIn('id', $teamOfEntry->values())->get(['id', 'user_id', 'bot_id'])->keyBy('id');

        foreach ($fixtures->groupBy('winner_entry_id') as $entryId => $won) {
            $team = $teams->get($teamOfEntry[$entryId] ?? 0);

            if (! $team) {
                continue;
            }

            $rounds = $won->pluck('round')->map(fn ($r) => (int) $r)->sort()->values()->all();
            // Wygrana runda liczy się temu, kto grał miejscem w tej kolejce.
            foreach ($this->byOwner($team->id, $rounds) as $owner => $ownerRounds) {
                $points = collect($ownerRounds)->filter(fn ($r) => $r < CupBracket::ROUNDS)->sum(fn ($r) => HallOfFame::value('cup_round_'.$r));
                $this->addOwner($competition, $team->id, $owner, 'cup_rounds', $points, null, ['rounds' => $ownerRounds]);
            }

            // Zwycięzca pucharu: punkty i trofeum dopiero po zakończeniu sezonu.
            if ($this->finished && in_array(CupBracket::ROUNDS, $rounds, true)) {
                $this->addTeam($competition, $team, 'cup_winner', HallOfFame::value('cup_winner'), CompetitionType::Cup->value);
            }
        }
    }

    /**
     * Liga Legend (LegendsLeague), na bieżąco po każdej kolejce:
     *  - wygrane i remisy w pojedynkach,
     *  - każda przetrwana kolejka (zespół grał w niej i nie odpadł po niej),
     * a po zakończeniu sezonu miejsca 1-3 tabeli końcowej (1. miejsce = trofeum, także gdy zespół wcześniej odpadł).
     */
    private function legends(Competition $competition, Collection $final): void
    {
        foreach ($this->results($competition) as $result) {
            $points = $result['won'] * HallOfFame::value('legends_win') + $result['drawn'] * HallOfFame::value('legends_draw');
            $this->addOwner($competition, $result['team'], $result['owner'], 'matches', $points, null, ['won' => $result['won'], 'drawn' => $result['drawn']]);
        }

        // Kolejki, w których zespół grał: id wpisu => [kolejka => true].
        $played = [];
        foreach (Fixture::where('competition_id', $competition->id)->whereNotNull('home_goals')->get(['round', 'home_entry_id', 'away_entry_id']) as $fixture) {
            foreach ([$fixture->home_entry_id, $fixture->away_entry_id] as $entryId) {
                if ($entryId !== null) {
                    $played[$entryId][$fixture->round] = true;
                }
            }
        }

        $entries = CompetitionEntry::with('seasonTeam:id,user_id,bot_id')->whereIn('id', array_keys($played))->get();

        foreach ($entries as $entry) {
            $team = $entry->seasonTeam;

            if (! $team instanceof SeasonTeam) {
                continue;
            }

            $out = $entry->eliminated_round;
            $rounds = array_values(array_filter(array_keys($played[$entry->id]), fn (int $round) => $out === null || $out > $round));
            sort($rounds);
            foreach ($this->byOwner($team->id, $rounds) as $owner => $ownerRounds) {
                $this->addOwner($competition, $team->id, $owner, 'legends_stages', count($ownerRounds) * HallOfFame::value('legends_survived'), null, ['rounds' => $ownerRounds]);
            }
        }

        if (! $this->finished) {
            return;
        }

        foreach ($final->take(3) as $standing) {
            $placeKey = [1 => 'champion', 2 => 'second', 3 => 'third'][$standing->place] ?? null;
            if ($placeKey && ($standing->stats['played'] ?? 0) > 0) {
                $this->add($competition, $standing, $placeKey, HallOfFame::value('legends_'.$placeKey), $standing->place === 1 ? CompetitionType::Legends->value : null);
            }
        }
    }

    /**
     * Nagrody indywidualne sezonu. MVP i Złote Rękawice z zestawu pytań lig (wspólny dla lig 1-10 i podwórkowej),
     * więc każdy ma te same szanse niezależnie od ligi. Złota Piłka: suma ofensywy (typ + bonus ofensywny)
     * ze wszystkich zestawów pytań zespołu w sezonie, czyli ze wszystkich rozgrywek, w których grał.
     *
     * @param  array<array-key, iterable<FinalStanding>>  $standings  tabele końcowe: id rozgrywek => wiersze FinalStanding
     */
    private function individual(array $standings): void
    {
        if (! $this->finished) {
            return;
        }

        $leagues = Competition::where('season_id', $this->season->id)
            ->whereIn('type', [CompetitionType::League->value, CompetitionType::Swiss->value])
            ->get()
            ->keyBy('id');

        // Zespół => wiersz tabeli końcowej jego ligi (miejsce, gole) i rozgrywki.
        /** @var array<int, array{standing: FinalStanding, competition: Competition, rank: int, goals: int, gloves: int, outcomes: int, exact: int, diffs: int}> $rows */
        $rows = [];
        foreach ($leagues as $competition) {
            $tier = $competition->type === CompetitionType::Swiss ? League::Podworkowa->value : (int) $competition->tier;

            foreach ($standings[$competition->id] ?? [] as $standing) {
                $rows[$standing->season_team_id] = [
                    'standing' => $standing,
                    'competition' => $competition,
                    'rank' => $tier * 10000 + $standing->place, // remis: wyższa liga, potem wyższe miejsce
                    'goals' => 0,
                    'gloves' => 0, 'outcomes' => 0, 'exact' => 0, 'diffs' => 0,
                ];
            }
        }

        if ($rows === []) {
            return;
        }

        // Sumy z kolejek sezonu (TeamScore, zestaw lig). Wartości logiczne sumujemy jako 0/1.
        $sums = TeamScore::query()
            ->join('matchdays', 'matchdays.id', '=', 'team_scores.matchday_id')
            ->where('matchdays.season_id', $this->season->id)
            ->whereIn('team_scores.season_team_id', array_keys($rows))
            ->groupBy('team_scores.season_team_id', 'team_scores.user_id', 'team_scores.bot_id')
            ->selectRaw('team_scores.season_team_id as team_id, team_scores.user_id as owner_user, team_scores.bot_id as owner_bot,
                sum(team_scores.offense) as goals,
                sum(case when team_scores.question_set = ? then team_scores.defense_bonus else 0 end) as league_gloves,
                sum(case when team_scores.question_set = ? and team_scores.outcome_hit then 1 else 0 end) as outcomes,
                sum(case when team_scores.question_set = ? and team_scores.exact_hit then 1 else 0 end) as exact,
                sum(case when team_scores.question_set = ? and team_scores.diff_hit then 1 else 0 end) as diffs', array_fill(0, 4, CompetitionType::League->value))
            ->toBase()
            ->get();

        foreach ($sums as $sum) {
            $teamId = (int) $sum->team_id;
            $standing = isset($rows[$teamId]) ? $rows[$teamId]['standing'] : null;

            // Liczą się tylko kolejki właściciela z tabeli końcowej (nie bota, za którego wskoczył gracz).
            if ($standing && (int) $sum->owner_user === (int) $standing->user_id && (int) $sum->owner_bot === (int) $standing->bot_id) {
                $rows[$teamId]['goals'] = (int) $sum->goals; // ofensywa ze wszystkich zestawów
                $rows[$teamId]['gloves'] = (int) $sum->league_gloves; // tylko zestaw lig
                $rows[$teamId]['outcomes'] = (int) $sum->outcomes;
                $rows[$teamId]['exact'] = (int) $sum->exact;
                $rows[$teamId]['diffs'] = (int) $sum->diffs;
            }
        }

        $awards = [
            'mvp' => ['outcomes', 'exact', 'diffs'],
            'golden_ball' => ['goals'],
            'golden_gloves' => ['gloves'],
        ];

        foreach ($awards as $key => $criteria) {
            $winner = collect($rows)
                ->filter(fn ($row) => $row[$criteria[0]] > 0)
                ->sort(function ($a, $b) use ($criteria) {
                    foreach ($criteria as $criterion) {
                        if ($a[$criterion] !== $b[$criterion]) {
                            return $b[$criterion] <=> $a[$criterion];
                        }
                    }

                    return $a['rank'] <=> $b['rank'];
                })
                ->first();

            if ($winner) {
                $meta = array_intersect_key($winner, array_flip($criteria)) + ['place' => $winner['standing']->place];
                $this->add($winner['competition'], $winner['standing'], $key, HallOfFame::value($key), $key, $meta);
            }
        }
    }

    private function add(Competition $competition, FinalStanding $standing, string $kind, float $points, ?string $trophy = null, array $meta = []): void
    {
        $this->push($competition, $standing->season_team_id, $standing->user_id, $standing->bot_id, $kind, $points, $trophy, $meta);
    }

    private function addTeam(Competition $competition, SeasonTeam $team, string $kind, float $points, ?string $trophy = null, array $meta = []): void
    {
        $this->push($competition, $team->id, $team->user_id, $team->bot_id, $kind, $points, $trophy, $meta);
    }

    /** Wpis dla właściciela miejsca w danej kolejce ('u12' albo 'b34', Ownership). */
    private function addOwner(Competition $competition, int $teamId, string $owner, string $kind, float $points, ?string $trophy = null, array $meta = []): void
    {
        $id = (int) substr($owner, 1);
        $this->push($competition, $teamId, $owner[0] === 'u' ? $id : null, $owner[0] === 'b' ? $id : null, $kind, $points, $trophy, $meta);
    }

    /**
     * Kolejki miejsca podzielone według tego, kto nim wtedy grał.
     *
     * @param  array<int, int>  $rounds
     * @return array<string, list<int>> właściciel => kolejki
     */
    private function byOwner(int $teamId, array $rounds): array
    {
        $out = [];
        foreach ($rounds as $round) {
            if ($owner = Ownership::at($this->season->id, $teamId, (int) $round)) {
                $out[$owner][] = (int) $round;
            }
        }

        return $out;
    }

    private function push(Competition $competition, ?int $teamId, ?int $userId, ?int $botId, string $kind, float $points, ?string $trophy, array $meta): void
    {
        // Wpisy bez punktów i bez trofeum nic nie wnoszą.
        if ($points <= 0 && $trophy === null) {
            return;
        }

        $now = now();
        $this->rows[] = [
            'season_id' => $this->season->id,
            'competition_id' => $competition->id,
            'season_team_id' => $teamId,
            'user_id' => $userId,
            'bot_id' => $botId,
            'kind' => $kind,
            'trophy' => $trophy,
            'points' => round($points, 1),
            'meta' => $meta === [] ? null : json_encode($meta),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
