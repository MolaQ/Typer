<?php

namespace App\Actions\Results;

use App\Enums\CompetitionType;
use App\Models\BotTip;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Models\SeasonTeam;
use App\Models\TeamScore;
use App\Models\Tip;
use App\Support\EuropeanCup;
use App\Support\LegendsLeague;
use App\Support\Scoring;
use App\Support\Squad;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Przelicza kolejkę po wpisaniu wyniku meczu Lecha (regulamin, punkty 3, 6, 7, 8):
 *  1. boty dostają losowy typ 0-3 : 0-3 (raz, potem zostaje zapisany) i losowych zawodników (Squad::pickForBots),
 *  2. każdy zespół dostaje dorobek (team_scores) dla każdego typu swoich rozgrywek (score_set),
 *  3. mecze tej kolejki we wszystkich rozgrywkach dostają wynik,
 *  4. w pucharze (i w fazie pucharowej pucharów europejskich) zwycięzca wchodzi na lepsze miejsce pary
 *     w meczu następnej rundy, a remis rozstrzyga wcześniej zapisany typ (mniejszy numer zapisu),
 *  5. w Lidze Legend odpadają zespoły z dwiema porażkami w tej kolejce (LegendsLeague),
 *  6. po ostatniej kolejce grupowej pucharów europejskich powstaje drabinka 1/8 finału (EuropeanCup::drawKnockout),
 *  7. kto odpadł z pucharu, pucharu europejskiego albo Ligi Legend, nie dostaje dorobku w zestawie tych rozgrywek
 *     w kolejnych kolejkach. Czy gra, wynika zawsze z eliminated_round, więc przeliczenie kolejki,
 *     które zmieni awans, zmienia też to. Złota Piłka i Złote Rękawice liczą się z team_scores, więc też tego nie widzą.
 * Bonusy ataku i obrony (offense_bonus, defense_bonus) na razie są zerowe: pytania usunięto, punkty
 * desygnowanych zawodników (gole, asysty, czyste konto) dojdą w tym miejscu.
 * Można wywołać wielokrotnie: przeliczenie nadpisuje poprzednie wyniki tej kolejki.
 * Runda rozgrywek = numer kolejki.
 */
class ScoreMatchday
{
    /**
     * @return array{teams: int, fixtures: int, eliminated: int}
     *
     * @throws DomainException
     */
    public function handle(Matchday $matchday): array
    {
        if ($matchday->lech_goals === null || $matchday->opponent_goals === null) {
            throw new DomainException(__('Enter the result of the Lech match first.'));
        }

        return DB::transaction(function () use ($matchday): array {
            $kickoff = ($matchday->kickoff_at ?? now())->format('Y-m-d H:i:s.u');

            $teams = SeasonTeam::where('season_id', $matchday->season_id)->get(['id', 'user_id', 'bot_id']);

            // Kto grał miejscem w tej kolejce. Przy ponownym przeliczeniu zostaje właściciel z pierwszego rozliczenia
            // (gracz, który wskoczył za bota później, nie przejmuje tej kolejki), App\Support\Ownership.
            // Typ też bierzemy od tamtego właściciela (tylko w pamięci, bez zapisu miejsca).
            $owners = TeamScore::where('matchday_id', $matchday->id)->get(['season_team_id', 'user_id', 'bot_id'])->keyBy('season_team_id');
            foreach ($teams as $team) {
                if ($owner = $owners->get($team->id)) {
                    $team->user_id = $owner->user_id;
                    $team->bot_id = $owner->bot_id;
                }
            }

            // Rozgrywki sezonu i to, w których typach rozgrywek (score_set) gra każdy zespół.
            $competitions = Competition::where('season_id', $matchday->season_id)->get()->keyBy('id');
            // Puchary i Liga Legend: przeliczenie kolejki cofa odpadnięcia z tej kolejki (liczymy je od nowa niżej).
            $legends = $competitions->first(fn ($c) => $c->type === CompetitionType::Legends);
            $knockout = $competitions->filter(fn ($c) => $c->type->isEuropean() || in_array($c->type, [CompetitionType::Cup, CompetitionType::Legends], true));
            CompetitionEntry::whereIn('competition_id', $knockout->keys())
                ->where('eliminated_round', '>=', $matchday->number)
                ->update(['eliminated_round' => null]);

            $entries = CompetitionEntry::whereIn('competition_id', $competitions->keys())
                ->get(['id', 'competition_id', 'season_team_id', 'seed', 'eliminated_round'])
                ->keyBy('id');

            $setsOfTeam = [];
            foreach ($entries as $entry) {
                if ($entry->eliminated_round !== null && $entry->eliminated_round < $matchday->number) {
                    continue; // odpadł z Ligi Legend we wcześniejszej kolejce
                }

                $set = $competitions[$entry->competition_id]->type->scoreSet()->value;
                $setsOfTeam[$entry->season_team_id][$set] = true;
            }

            $botTips = $this->botTips($matchday, $teams->whereNull('user_id')->pluck('id')->all());

            // Boty wystawiają zawodników w swoich typach rozgrywek (losowo, z blokadami, Support\Squad).
            $setsOfBot = [];
            foreach ($teams as $team) {
                if ($team->user_id === null && $team->bot_id !== null && isset($setsOfTeam[$team->id])) {
                    $setsOfBot[$team->bot_id] = array_keys($setsOfTeam[$team->id]);
                }
            }
            Squad::pickForBots($matchday, $setsOfBot);
            $humanTips = Tip::where('matchday_id', $matchday->id)->get()->keyBy('user_id');

            // --- Dorobek zespołów ---
            $scores = [];
            $rows = [];
            $now = now();

            foreach ($teams as $team) {
                $tip = $team->user_id ? $humanTips->get($team->user_id) : $botTips[$team->id] ?? null;
                $tipResult = $tip
                    ? Scoring::tip($tip->lech_goals, $tip->opponent_goals, $matchday->lech_goals, $matchday->opponent_goals)
                    : ['points' => 0, 'outcome' => false, 'diff' => false, 'exact' => false];

                // Bot i brak typu: czas typu = godzina meczu, bez numeru zapisu (za wszystkimi typami ludzi).
                $tippedAt = $tip instanceof Tip ? $tip->saved_at->format('Y-m-d H:i:s.u') : $kickoff;
                $tipOrder = $tip instanceof Tip ? $tip->save_order : null;

                foreach (array_keys($setsOfTeam[$team->id] ?? []) as $set) {
                    // Atak i obrona zawodników: na razie 0 (desygnowanie zawodników w przygotowaniu).
                    $offense = 0;
                    $defense = 0;

                    $row = [
                        'matchday_id' => $matchday->id,
                        'season_team_id' => $team->id,
                        'user_id' => $team->user_id,
                        'bot_id' => $team->bot_id,
                        'score_set' => $set,
                        'has_tip' => $tip !== null,
                        'tip_lech' => $tip?->lech_goals,
                        'tip_opponent' => $tip?->opponent_goals,
                        'tipped_at' => $tippedAt,
                        'tip_order' => $tipOrder,
                        'tip_points' => $tipResult['points'],
                        'outcome_hit' => $tipResult['outcome'],
                        'diff_hit' => $tipResult['diff'],
                        'exact_hit' => $tipResult['exact'],
                        'offense_bonus' => $offense,
                        'defense_bonus' => $defense,
                        'offense' => $tipResult['points'] + $offense,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $rows[] = $row;
                    $scores[$team->id][$set] = $row;
                }
            }

            TeamScore::where('matchday_id', $matchday->id)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                TeamScore::insert($chunk);
            }

            // --- Mecze kolejki ---
            $fixtures = Fixture::whereIn('competition_id', $competitions->keys())
                ->where('round', $matchday->number)
                ->whereNotNull('home_entry_id')
                ->get();

            $scored = 0;

            foreach ($fixtures as $fixture) {
                $competition = $competitions[$fixture->competition_id];
                $set = $competition->type->scoreSet()->value;
                $home = $scores[$entries[$fixture->home_entry_id]->season_team_id][$set] ?? null;

                if ($fixture->isBye()) {
                    // Wirtualny rywal (Lech Poznań): bramki Lecha z prawdziwego meczu minus defensywa gracza.
                    $homeGoals = $home['offense'] ?? 0;
                    $awayGoals = Scoring::goals($matchday->lech_goals, $home['defense_bonus'] ?? 0);
                } elseif ($fixture->away_entry_id !== null) {
                    $away = $scores[$entries[$fixture->away_entry_id]->season_team_id][$set] ?? null;
                    $homeGoals = Scoring::goals($home['offense'] ?? 0, $away['defense_bonus'] ?? 0);
                    $awayGoals = Scoring::goals($away['offense'] ?? 0, $home['defense_bonus'] ?? 0);
                } else {
                    continue; // puchar: rywal jeszcze nieznany
                }

                $winner = null;
                $byTime = false;

                if ($homeGoals > $awayGoals) {
                    $winner = $fixture->home_entry_id;
                } elseif ($awayGoals > $homeGoals) {
                    $winner = $fixture->away_entry_id;
                } elseif ($competition->type->decidedByTime($fixture->round)) {
                    $byTime = true;
                    $winner = Scoring::homeWinsOnTime($home['tip_order'] ?? null, $away['tip_order'] ?? null)
                        ? $fixture->home_entry_id
                        : $fixture->away_entry_id;
                }

                $fixture->update([
                    'home_goals' => $homeGoals,
                    'away_goals' => $awayGoals,
                    'winner_entry_id' => $winner,
                    'decided_by_time' => $byTime,
                ]);

                if ($competition->type->decidedByTime($fixture->round) && $winner !== null) {
                    $this->advance($fixture, $winner);

                    // Przegrany odpada z pucharu po tej rundzie.
                    $loser = $winner === $fixture->home_entry_id ? $fixture->away_entry_id : $fixture->home_entry_id;
                    CompetitionEntry::whereKey($loser)->update(['eliminated_round' => $matchday->number]);
                }

                $scored++;
            }

            $eliminated = $legends ? LegendsLeague::eliminate($legends, $matchday->number) : 0;

            // Puchary europejskie: po ostatniej kolejce grupowej drabinka 1/8 finału, miejsca 5-6 odpadają.
            if ($matchday->number === EuropeanCup::GROUP_ROUNDS) {
                foreach ($competitions->filter(fn ($c) => $c->type->isEuropean()) as $european) {
                    EuropeanCup::drawKnockout($european);
                }
            }

            return ['teams' => count($scores), 'fixtures' => $scored, 'eliminated' => $eliminated];
        });
    }

    /**
     * Losowe typy botów tej kolejki (tworzone raz, przy pierwszym przeliczeniu).
     *
     * @param  array<int, int>  $botTeamIds
     * @return array<int, BotTip> season_team_id => typ
     */
    private function botTips(Matchday $matchday, array $botTeamIds): array
    {
        $existing = BotTip::where('matchday_id', $matchday->id)->get()->keyBy('season_team_id');
        $now = now();
        $rows = [];

        foreach ($botTeamIds as $teamId) {
            if (! $existing->has($teamId)) {
                $rows[] = [
                    'matchday_id' => $matchday->id,
                    'season_team_id' => $teamId,
                    'lech_goals' => random_int(0, BotTip::MAX_GOALS),
                    'opponent_goals' => random_int(0, BotTip::MAX_GOALS),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            BotTip::insert($chunk);
        }

        return BotTip::where('matchday_id', $matchday->id)->get()->keyBy('season_team_id')->all();
    }

    /** Puchar: zwycięzca zajmuje lepsze miejsce pary (home_seat) w meczu następnej rundy. */
    private function advance(Fixture $fixture, int $winnerEntryId): void
    {
        $seat = min($fixture->home_seat, $fixture->away_seat);

        $next = Fixture::where('competition_id', $fixture->competition_id)
            ->where('round', $fixture->round + 1)
            ->where(fn ($q) => $q->where('home_seat', $seat)->orWhere('away_seat', $seat))
            ->first();

        if (! $next) {
            return; // finał
        }

        $next->update($next->home_seat === $seat ? ['home_entry_id' => $winnerEntryId] : ['away_entry_id' => $winnerEntryId]);
    }
}
