<?php

namespace App\Actions\Results;

use App\Enums\CompetitionType;
use App\Enums\QuestionSide;
use App\Models\BotTip;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Models\MatchdayQuestion;
use App\Models\SeasonTeam;
use App\Models\TeamScore;
use App\Models\Tip;
use App\Models\TipAnswer;
use App\Support\Scoring;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Przelicza kolejkę po wpisaniu wyniku meczu Lecha i poprawnych odpowiedzi (regulamin, punkty 3, 6, 7, 8):
 *  1. boty dostają losowy typ 0-3 : 0-3 (raz, potem zostaje zapisany),
 *  2. każdy zespół dostaje dorobek (team_scores) dla każdego zestawu pytań swoich rozgrywek,
 *  3. mecze tej kolejki we wszystkich rozgrywkach dostają wynik,
 *  4. w pucharze zwycięzca wchodzi na lepsze miejsce pary w meczu następnej rundy.
 * Można wywołać wielokrotnie: przeliczenie nadpisuje poprzednie wyniki tej kolejki.
 * Runda rozgrywek = numer kolejki.
 */
class ScoreMatchday
{
    /**
     * @return array{teams: int, fixtures: int}
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

            $teams = SeasonTeam::where('season_id', $matchday->season_id)->get(['id', 'user_id']);

            // Rozgrywki sezonu i to, w których zestawach pytań gra każdy zespół.
            $competitions = Competition::where('season_id', $matchday->season_id)->get()->keyBy('id');
            $entries = CompetitionEntry::whereIn('competition_id', $competitions->keys())
                ->get(['id', 'competition_id', 'season_team_id', 'seed'])
                ->keyBy('id');

            $setsOfTeam = [];
            foreach ($entries as $entry) {
                $set = $competitions[$entry->competition_id]->type->questionSet()->value;
                $setsOfTeam[$entry->season_team_id][$set] = true;
            }

            $botTips = $this->botTips($matchday, $teams->whereNull('user_id')->pluck('id')->all());
            $humanTips = Tip::where('matchday_id', $matchday->id)->get()->keyBy('user_id');

            // Odpowiedzi graczy: user_id => [id miejsca => odpowiedź].
            $answers = [];
            foreach (TipAnswer::where('matchday_id', $matchday->id)->get(['user_id', 'matchday_question_id', 'answer']) as $row) {
                $answers[$row->user_id][$row->matchday_question_id] = (bool) $row->answer;
            }

            // Poprawne odpowiedzi: zestaw => strona => [id miejsca => odpowiedź].
            $correct = [];
            foreach (MatchdayQuestion::where('matchday_id', $matchday->id)->get() as $slot) {
                $correct[$slot->competition_type->value][$slot->side->value][$slot->id] = $slot->correct_answer;
            }

            // --- Dorobek zespołów ---
            $scores = [];
            $rows = [];
            $now = now();

            foreach ($teams as $team) {
                $tip = $team->user_id ? $humanTips->get($team->user_id) : $botTips[$team->id] ?? null;
                $tipResult = $tip
                    ? Scoring::tip($tip->lech_goals, $tip->opponent_goals, $matchday->lech_goals, $matchday->opponent_goals)
                    : ['points' => 0, 'outcome' => false, 'diff' => false, 'exact' => false];

                // Bot i brak typu: czas typu = godzina meczu.
                $tippedAt = $tip instanceof Tip ? $tip->saved_at->format('Y-m-d H:i:s.u') : $kickoff;

                foreach (array_keys($setsOfTeam[$team->id] ?? []) as $set) {
                    // Boty i gracze bez typu nie dostają punktów z pytań.
                    $playerAnswers = $tip instanceof Tip ? ($answers[$team->user_id] ?? []) : [];
                    $offense = Scoring::set($playerAnswers, $correct[$set][QuestionSide::Offensive->value] ?? []);
                    $defense = Scoring::set($playerAnswers, $correct[$set][QuestionSide::Defensive->value] ?? []);

                    $row = [
                        'matchday_id' => $matchday->id,
                        'season_team_id' => $team->id,
                        'question_set' => $set,
                        'has_tip' => $tip !== null,
                        'tip_lech' => $tip?->lech_goals,
                        'tip_opponent' => $tip?->opponent_goals,
                        'tipped_at' => $tippedAt,
                        'tip_points' => $tipResult['points'],
                        'outcome_hit' => $tipResult['outcome'],
                        'diff_hit' => $tipResult['diff'],
                        'exact_hit' => $tipResult['exact'],
                        'offense_bonus' => $offense['points'],
                        'defense_bonus' => $defense['points'],
                        'offense_zeroed' => $offense['zeroed'],
                        'defense_zeroed' => $defense['zeroed'],
                        'offense' => $tipResult['points'] + $offense['points'],
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
                $set = $competition->type->questionSet()->value;
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
                } elseif ($competition->type === CompetitionType::Cup) {
                    $byTime = true;
                    $winner = Scoring::homeWinsOnTime($home['tipped_at'] ?? null, $away['tipped_at'] ?? null)
                        ? $fixture->home_entry_id
                        : $fixture->away_entry_id;
                }

                $fixture->update([
                    'home_goals' => $homeGoals,
                    'away_goals' => $awayGoals,
                    'winner_entry_id' => $winner,
                    'decided_by_time' => $byTime,
                ]);

                if ($competition->type === CompetitionType::Cup && $winner !== null) {
                    $this->advance($fixture, $winner);
                }

                $scored++;
            }

            return ['teams' => count($scores), 'fixtures' => $scored];
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
            if (!$existing->has($teamId)) {
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
            ->where(fn($q) => $q->where('home_seat', $seat)->orWhere('away_seat', $seat))
            ->first();

        if (!$next) {
            return; // finał
        }

        $next->update($next->home_seat === $seat ? ['home_entry_id' => $winnerEntryId] : ['away_entry_id' => $winnerEntryId]);
    }
}
