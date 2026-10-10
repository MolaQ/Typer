<?php

namespace App\Actions\Results;

use App\Enums\CompetitionType;
use App\Enums\QuestionSide;
use App\Enums\RoleName;
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
use App\Models\User;
use App\Support\LegendsRanking;
use App\Support\Premium;
use App\Support\Scoring;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Przelicza kolejkę po wpisaniu wyniku meczu Lecha i poprawnych odpowiedzi (regulamin, punkty 3, 6, 7, 8):
 *  1. boty dostają losowy typ 0-3 : 0-3 (raz, potem zostaje zapisany), a gracze premium bez typu swój domyślny typ,
 *  2. każdy zespół dostaje dorobek (team_scores) dla każdego zestawu pytań swoich rozgrywek,
 *  3. mecze tej kolejki we wszystkich rozgrywkach dostają wynik,
 *  4. w pucharze zwycięzca wchodzi na lepsze miejsce pary w meczu następnej rundy,
 *  5. w Lidze Legend odpadają zespoły poza limitem po tej kolejce (LegendsRanking),
 *  6. kto odpadł z pucharu albo Ligi Legend, traci odpowiedzi na pytania tych rozgrywek w kolejnych kolejkach.
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

            $teams = SeasonTeam::where('season_id', $matchday->season_id)->get(['id', 'user_id']);

            // Gracze premium bez typu dostają swój domyślny typ (regulamin, punkt 11).
            $this->premiumDefaults($matchday, $teams->whereNotNull('user_id')->pluck('user_id')->all());

            // Rozgrywki sezonu i to, w których zestawach pytań gra każdy zespół.
            $competitions = Competition::where('season_id', $matchday->season_id)->get()->keyBy('id');
            // Puchar i Liga Legend: przeliczenie kolejki cofa odpadnięcia z tej kolejki (liczymy je od nowa niżej).
            $legends = $competitions->first(fn ($c) => $c->type === CompetitionType::Legends);
            $knockout = $competitions->filter(fn ($c) => in_array($c->type, [CompetitionType::Cup, CompetitionType::Legends], true));
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

                    // Przegrany odpada z pucharu po tej rundzie.
                    $loser = $winner === $fixture->home_entry_id ? $fixture->away_entry_id : $fixture->home_entry_id;
                    CompetitionEntry::whereKey($loser)->update(['eliminated_round' => $matchday->number]);
                }

                $scored++;
            }

            $eliminated = $legends ? LegendsRanking::eliminate($legends, $matchday->number) : 0;

            $this->dropAnswersOfEliminated($matchday, $knockout);

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

    /**
     * Kto odpadł z pucharu albo Ligi Legend, nie gra już w tych rozgrywkach: jego odpowiedzi na ich pytania
     * w kolejnych kolejkach są usuwane i nie liczą się do niczego.
     *
     * @param  Collection<int, Competition>  $knockout
     */
    private function dropAnswersOfEliminated(Matchday $matchday, $knockout): void
    {
        $later = Matchday::where('season_id', $matchday->season_id)->where('number', '>', $matchday->number)->pluck('id');

        if ($later->isEmpty()) {
            return;
        }

        foreach ($knockout as $competition) {
            $out = CompetitionEntry::where('competition_id', $competition->id)->whereNotNull('eliminated_round')->select('season_team_id');
            $userIds = SeasonTeam::whereIn('id', $out)->whereNotNull('user_id')->pluck('user_id');

            if ($userIds->isEmpty()) {
                continue;
            }

            $slotIds = MatchdayQuestion::whereIn('matchday_id', $later)
                ->where('competition_type', $competition->type->questionSet()->value)
                ->pluck('id');

            TipAnswer::whereIn('user_id', $userIds)->whereIn('matchday_question_id', $slotIds)->delete();
        }
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

    /**
     * Domyślny typ premium (regulamin, punkt 11): gracz, który w chwili meczu ma aktywne premium i nie wytypował,
     * dostaje swój domyślny typ (0:0, dopóki go nie ustawi), bez odpowiedzi na pytania. Czas typu = godzina meczu.
     *
     * @param  array<int, int>  $userIds  gracze z listy sezonu
     */
    private function premiumDefaults(Matchday $matchday, array $userIds): void
    {
        $kickoff = $matchday->kickoff_at ?? now();
        $tipped = Tip::where('matchday_id', $matchday->id)->pluck('user_id')->all();

        $users = User::whereIn('id', array_diff($userIds, $tipped))
            // Premium z datą ważną w chwili meczu albo bezterminowe (rola Premium bez daty).
            ->where(fn ($q) => $q->where('premium_until', '>', $kickoff)
                ->orWhere(fn ($w) => $w->whereNull('premium_until')->whereHas('roles', fn ($r) => $r->where('name', RoleName::Premium->value))))
            ->get(['id', 'premium_until', 'default_tip_lech', 'default_tip_opponent']);

        foreach ($users as $user) {
            [$lech, $opponent] = Premium::defaultTip($user);

            Tip::create([
                'matchday_id' => $matchday->id,
                'user_id' => $user->id,
                'lech_goals' => $lech,
                'opponent_goals' => $opponent,
                'is_default' => true,
                'saved_at' => $kickoff,
            ]);
        }
    }
}
