<?php

namespace App\Actions\Seasons;

use App\Enums\CompetitionType;
use App\Enums\League;
use App\Enums\MatchdayStatus;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\FinalStanding;
use App\Models\Fixture;
use App\Models\HallOfFameAward;
use App\Models\Matchday;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\TeamScore;
use App\Support\CupBracket;
use App\Support\HallOfFame;
use App\Support\LegendsRanking;
use App\Support\Promotion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Przyznaje punkty i trofea Hall of Fame za sezon (regulamin, punkt 12). Liczy się na bieżąco po każdym
 * przeliczeniu kolejki (SaveMatchdayResult) i jeszcze raz przy zakończeniu sezonu (FinishSeason).
 * W trakcie sezonu wchodzą tylko punkty za mecze (z terminarza): wygrane i remisy w ligach, wygrane
 * w ligach europejskich, wygrane rundy pucharu. Miejsca, awanse, król strzelców, Liga Legend, tytuły
 * i trofea dochodzą dopiero z tabel końcowych (final_standings), czyli po zakończeniu sezonu.
 * Przeliczenie kasuje poprzednie wpisy sezonu, więc można je powtarzać (np. po zmianie punktacji w panelu).
 * Punkty bieżącego sezonu nie wpływają na kryterium HoF w tabelach tego sezonu (HallOfFame::pointsBefore).
 *  - ligi i podwórkowa (× mnożnik poziomu): wygrane i remisy, miejsca 1-3, awans, król strzelców,
 *  - Puchar Polski: wygrana w każdej rundzie, zwycięzca finału,
 *  - ligi europejskie: wygrane mecze i zwycięstwo,
 *  - Liga Legend: punkty Legend z każdej kolejki i premie za przejście rund (na bieżąco), wygrana w finale,
 *  - Złota Liga: tylko trofeum, bez punktów,
 *  - nagrody indywidualne (po zakończeniu sezonu, tylko gracze): MVP sezonu (najwięcej trafionych rozstrzygnięć,
 *    potem dokładne wyniki, potem różnice), Złota Piłka (najwięcej goli w lidze) i Złote Rękawice (najwięcej punktów
 *    z bonusów defensywnych); przy remisie wyższe miejsce w lidze (poziom, potem miejsce w tabeli końcowej).
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
                    CompetitionType::Golden => $this->golden($competition, $final),
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
            $this->addTeam($competition, $result['team'], 'matches', $points, null, ['won' => $result['won'], 'drawn' => $result['drawn']]);
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

    /** Liga Mistrzów, Europy i Konferencji: wygrane na bieżąco, tytuł po zakończeniu sezonu. */
    private function european(Competition $competition, Collection $final): void
    {
        $type = $competition->type->value;

        foreach ($this->results($competition) as $result) {
            $this->addTeam($competition, $result['team'], 'matches', $result['won'] * HallOfFame::value($type.'_win'), null, ['won' => $result['won']]);
        }

        $winner = $final->first();
        if ($winner && $winner->place === 1 && ($winner->stats['played'] ?? 0) > 0) {
            $this->add($competition, $winner, 'title', HallOfFame::value($type.'_winner'), $type);
        }
    }

    /**
     * Wygrane i remisy zespołów z rozegranych meczów terminarza. Wolny los w podwórkowej (wirtualny
     * rywal) liczy się jak zwykły mecz, tak jak w tabeli.
     *
     * @return list<array{team: SeasonTeam, won: int, drawn: int}>
     */
    private function results(Competition $competition): array
    {
        $fixtures = Fixture::where('competition_id', $competition->id)
            ->whereNotNull('home_goals')->whereNotNull('away_goals')
            ->get(['home_entry_id', 'away_entry_id', 'home_goals', 'away_goals']);

        $counts = [];
        foreach ($fixtures as $fixture) {
            foreach ([[$fixture->home_entry_id, $fixture->home_goals, $fixture->away_goals], [$fixture->away_entry_id, $fixture->away_goals, $fixture->home_goals]] as [$entryId, $for, $against]) {
                if (! $entryId) {
                    continue; // wirtualny rywal
                }
                $counts[$entryId] ??= ['won' => 0, 'drawn' => 0];
                $counts[$entryId]['won'] += (int) ($for > $against);
                $counts[$entryId]['drawn'] += (int) ($for === $against);
            }
        }

        if ($counts === []) {
            return [];
        }

        $teamOfEntry = CompetitionEntry::whereIn('id', array_keys($counts))->pluck('season_team_id', 'id');
        $teams = SeasonTeam::whereIn('id', $teamOfEntry->values())->get(['id', 'user_id', 'bot_id'])->keyBy('id');

        $out = [];
        foreach ($counts as $entryId => $count) {
            if ($team = $teams->get($teamOfEntry[$entryId] ?? 0)) {
                $out[] = ['team' => $team] + $count;
            }
        }

        return $out;
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

            $rounds = $won->pluck('round')->sort()->values()->all();
            $points = collect($rounds)->filter(fn ($r) => $r < CupBracket::ROUNDS)->sum(fn ($r) => HallOfFame::value('cup_round_'.$r));
            $this->addTeam($competition, $team, 'cup_rounds', $points, null, ['rounds' => $rounds]);

            // Zwycięzca pucharu: punkty i trofeum dopiero po zakończeniu sezonu.
            if ($this->finished && in_array(CupBracket::ROUNDS, $rounds, true)) {
                $this->addTeam($competition, $team, 'cup_winner', HallOfFame::value('cup_winner'), CompetitionType::Cup->value);
            }
        }
    }

    /**
     * Liga Legend, na bieżąco po każdej kolejce:
     *  - punkty Legend z rozegranych kolejek (LegendsRanking::matchdayPoints, do 100 w kolejce) × przelicznik,
     *    tylko z kolejek, w których zespół jeszcze grał (odpadnięty w kolejce N grał w niej),
     *  - premia za przejście każdej rundy 1-8 (narastająco), a po zakończeniu sezonu wygrana w finale i trofeum.
     */
    private function legends(Competition $competition, Collection $final): void
    {
        $matchdays = Matchday::where('season_id', $this->season->id)
            ->where('status', MatchdayStatus::Played->value)
            ->pluck('number', 'id');

        if ($matchdays->isEmpty()) {
            return;
        }

        $lastPlayed = (int) $matchdays->max();
        $entries = CompetitionEntry::with('seasonTeam:id,user_id,bot_id')->where('competition_id', $competition->id)->get();
        $scores = TeamScore::whereIn('matchday_id', $matchdays->keys())
            ->whereIn('season_team_id', $entries->pluck('season_team_id'))
            ->where('question_set', CompetitionType::Legends->value)
            ->get()
            ->groupBy('season_team_id');

        $winnerTeamId = $this->finished ? $final->first()?->season_team_id : null;

        foreach ($entries as $entry) {
            $team = $entry->seasonTeam;

            if (! $team) {
                continue;
            }

            $out = $entry->eliminated_round;

            // Punkty Legend z kolejek, w których zespół grał.
            $points = $scores->get($team->id, collect())
                ->filter(fn (TeamScore $score) => $out === null || $matchdays[$score->matchday_id] <= $out)
                ->sum(fn (TeamScore $score) => LegendsRanking::matchdayPoints($score));
            $this->addTeam($competition, $team, 'legends_points', $points * HallOfFame::value('legends_point'), null, ['points' => $points]);

            // Przejście rund 1-8: po przeliczeniu rundy N zespół, który nie odpadł w niej ani wcześniej.
            $rounds = array_values(array_filter(
                range(1, min($lastPlayed, LegendsRanking::ROUNDS - 1)),
                fn (int $round) => $out === null || $out > $round,
            ));
            $bonus = array_sum(array_map(fn (int $round) => HallOfFame::value('legends_round_'.$round), $rounds));
            $this->addTeam($competition, $team, 'legends_stages', $bonus, null, ['rounds' => $rounds]);

            if ($winnerTeamId === $team->id) {
                $this->addTeam($competition, $team, 'legends_winner', HallOfFame::value('legends_winner'), CompetitionType::Legends->value);
            }
        }
    }

    /** Złota Liga: tylko unikalne trofeum dla zwycięzcy, bez punktów (regulamin). */
    private function golden(Competition $competition, Collection $final): void
    {
        $winner = $final->first();

        if ($winner && ($winner->stats['played'] ?? 0) > 0) {
            $this->add($competition, $winner, 'golden_title', 0, CompetitionType::Golden->value);
        }
    }

    /**
     * Nagrody indywidualne sezonu. Liczymy z zestawu pytań lig (wspólny dla lig 1-10 i podwórkowej),
     * więc każdy gracz ma te same szanse niezależnie od ligi. Gole z tabeli końcowej jego ligi.
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

        // Zespół ludzi => wiersz tabeli końcowej jego ligi (miejsce, gole) i rozgrywki.
        /** @var array<int, array{standing: FinalStanding, competition: Competition, rank: int, goals: int, gloves: int, outcomes: int, exact: int, diffs: int}> $rows */
        $rows = [];
        foreach ($leagues as $competition) {
            $tier = $competition->type === CompetitionType::Swiss ? League::Podworkowa->value : (int) $competition->tier;

            foreach ($standings[$competition->id] ?? [] as $standing) {
                if ($standing->user_id === null) {
                    continue; // boty nie dostają nagród indywidualnych
                }

                $rows[$standing->season_team_id] = [
                    'standing' => $standing,
                    'competition' => $competition,
                    'rank' => $tier * 10000 + $standing->place, // remis: wyższa liga, potem wyższe miejsce
                    'goals' => (int) ($standing->stats['for'] ?? 0),
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
            ->where('team_scores.question_set', CompetitionType::League->value)
            ->whereIn('team_scores.season_team_id', array_keys($rows))
            ->groupBy('team_scores.season_team_id')
            ->selectRaw('team_scores.season_team_id as team_id, sum(team_scores.defense_bonus) as gloves,
                sum(case when team_scores.outcome_hit then 1 else 0 end) as outcomes,
                sum(case when team_scores.exact_hit then 1 else 0 end) as exact,
                sum(case when team_scores.diff_hit then 1 else 0 end) as diffs')
            ->toBase()
            ->get();

        foreach ($sums as $sum) {
            $teamId = (int) $sum->team_id;

            if (isset($rows[$teamId])) {
                $rows[$teamId]['gloves'] = (int) $sum->gloves;
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
