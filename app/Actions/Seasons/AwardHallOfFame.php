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
use App\Support\CupBracket;
use App\Support\HallOfFame;
use App\Support\Promotion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Przyznaje punkty i trofea Hall of Fame za zakończony sezon (regulamin, punkt 12), z tabel końcowych
 * (final_standings) i meczów pucharu. Przeliczenie kasuje poprzednie wpisy sezonu, więc można je powtarzać
 * (np. po zmianie wartości punktacji w panelu).
 *  - ligi i podwórkowa (× mnożnik poziomu): wygrane i remisy, miejsca 1-3, awans, król strzelców,
 *  - Puchar Polski: wygrana w każdej rundzie, zwycięzca finału,
 *  - ligi europejskie: wygrane mecze i zwycięstwo,
 *  - Liga Legend: etapy narastająco (16, 8, 4 najlepszych, finał, zwycięstwo),
 *  - Złota Liga: tylko trofeum, bez punktów.
 */
class AwardHallOfFame
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    private Season $season;

    /** @return int liczba zapisanych wpisów */
    public function handle(Season $season): int
    {
        $this->season = $season;
        $this->rows = [];

        return DB::transaction(function (): int {
            HallOfFameAward::where('season_id', $this->season->id)->delete();

            $standings = FinalStanding::where('season_id', $this->season->id)->orderBy('place')->get()->groupBy('competition_id');

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
        if ($final->isEmpty() || $final->max(fn($s) => $s->stats['played'] ?? 0) === 0) {
            return; // rozgrywki bez rozegranych meczów
        }

        $tier = $competition->type === CompetitionType::Swiss ? League::Podworkowa->value : (int) $competition->tier;
        $multiplier = HallOfFame::multiplier($tier);
        $topGoals = (int) $final->max(fn($s) => $s->stats['for'] ?? 0);
        $topScorerGiven = false;

        foreach ($final as $standing) {
            $won = (int) ($standing->stats['won'] ?? 0);
            $drawn = (int) ($standing->stats['drawn'] ?? 0);

            $matches = ($won * HallOfFame::value('league_win') + $drawn * HallOfFame::value('league_draw')) * $multiplier;
            $this->add($competition, $standing, 'matches', $matches, null, ['won' => $won, 'drawn' => $drawn]);

            $placeKey = [1 => 'champion', 2 => 'second', 3 => 'third'][$standing->place] ?? null;
            if ($placeKey) {
                $trophy = $standing->place === 1 ? 'league_' . $tier : null;
                $this->add($competition, $standing, $placeKey, HallOfFame::value('league_' . $placeKey) * $multiplier, $trophy);
            }

            // Awans: 4 najlepsze z lig 2-10 i z podwórkowej (z Ekstraklasy nikt nie awansuje).
            if ($tier > 1 && $standing->place <= Promotion::MOVES) {
                $this->add($competition, $standing, 'promotion', HallOfFame::value('league_promotion') * $multiplier);
            }

            // Król strzelców: najwięcej bramek w lidze; przy równej liczbie wyżej w tabeli.
            if (!$topScorerGiven && $topGoals > 0 && (int) ($standing->stats['for'] ?? 0) === $topGoals) {
                $topScorerGiven = true;
                $this->add($competition, $standing, 'top_scorer', HallOfFame::value('league_top_scorer') * $multiplier, 'top_scorer_' . $tier, ['goals' => $topGoals]);
            }
        }
    }

    /** Liga Mistrzów, Europy i Konferencji. */
    private function european(Competition $competition, Collection $final): void
    {
        $type = $competition->type->value;

        foreach ($final as $standing) {
            $won = (int) ($standing->stats['won'] ?? 0);
            $this->add($competition, $standing, 'matches', $won * HallOfFame::value($type . '_win'), null, ['won' => $won]);

            if ($standing->place === 1 && ($standing->stats['played'] ?? 0) > 0) {
                $this->add($competition, $standing, 'title', HallOfFame::value($type . '_winner'), $type);
            }
        }
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

            if (!$team) {
                continue;
            }

            $rounds = $won->pluck('round')->sort()->values()->all();
            $points = collect($rounds)->filter(fn($r) => $r < CupBracket::ROUNDS)->sum(fn($r) => HallOfFame::value('cup_round_' . $r));
            $this->addTeam($competition, $team, 'cup_rounds', $points, null, ['rounds' => $rounds]);

            if (in_array(CupBracket::ROUNDS, $rounds, true)) {
                $this->addTeam($competition, $team, 'cup_winner', HallOfFame::value('cup_winner'), CompetitionType::Cup->value);
            }
        }
    }

    /**
     * Liga Legend: etapy liczone z miejsca końcowego, narastająco. Etapy 16/8/4 tylko wtedy, gdy było
     * więcej uczestników (inaczej nikt nie odpadł i to nie jest awans).
     */
    private function legends(Competition $competition, Collection $final): void
    {
        $count = $final->count();
        $stages = [16 => 'legends_top16', 8 => 'legends_top8', 4 => 'legends_top4', 2 => 'legends_final', 1 => 'legends_winner'];

        foreach ($final as $standing) {
            $reached = [];
            foreach ($stages as $limit => $key) {
                if ($standing->place <= $limit && ($limit <= 2 || $count > $limit)) {
                    $reached[] = $limit;
                }
            }

            if ($reached === []) {
                continue;
            }

            $points = array_sum(array_map(fn($limit) => HallOfFame::value($stages[$limit]), $reached));
            $this->add($competition, $standing, 'legends_stages', $points, $standing->place === 1 ? CompetitionType::Legends->value : null, ['place' => $standing->place]);
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
