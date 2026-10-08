<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Enums\MatchdayStatus;
use App\Enums\QuestionSide;
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

/**
 * Podgląd rywali gracza w kolejce (mecze z terminarza z rundą = numer kolejki), z widocznością zależną od etapu:
 *  - typowanie otwarte: każdy widzi, czy rywal typował; premium widzi też rozstrzygnięcie i ryzyko (gwiazdki),
 *  - typowanie zamknięte, bez wyników: każdy widzi, czy typował, i ryzyko; premium widzi dokładny typ,
 *  - po wpisaniu wyników: pełne szczegóły dla wszystkich (typ, bonusy, wynik meczu).
 * Ryzyko = liczba odpowiedzi rywala na 5 pytań ofensywnych i 5 defensywnych zestawu tych rozgrywek.
 * Boty typują dopiero przy przeliczeniu kolejki, więc przed wynikami nie mają typu.
 */
final class Rivals
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';
    public const PLAYED = 'played';

    public static function phase(Matchday $matchday): string
    {
        if ($matchday->status === MatchdayStatus::Played) {
            return self::PLAYED;
        }

        return $matchday->isOpenForTips() ? self::OPEN : self::CLOSED;
    }

    /**
     * @return array<int, array{competition: string, rival: string, bot: bool, virtual: bool, tipped: ?bool,
     *   outcome: ?string, tip: ?string, offense: ?int, defense: ?int, bonus: ?string, score: ?string}>
     */
    public static function forUser(User $viewer, Matchday $matchday): array
    {
        $team = SeasonTeam::where('season_id', $matchday->season_id)->where('user_id', $viewer->id)->first();

        if (!$team) {
            return [];
        }

        $phase = self::phase($matchday);
        $premium = Premium::isActive($viewer);
        $round = $matchday->number;

        $competitions = Competition::where('season_id', $matchday->season_id)->get()->keyBy('id');
        $mine = CompetitionEntry::whereIn('competition_id', $competitions->keys())
            ->where('season_team_id', $team->id)
            ->where(fn($q) => $q->whereNull('eliminated_round')->orWhere('eliminated_round', '>=', $round))
            ->pluck('id');

        $fixtures = Fixture::with(['home.seasonTeam.user:id,name,team_name', 'home.seasonTeam.bot:id,name', 'away.seasonTeam.user:id,name,team_name', 'away.seasonTeam.bot:id,name'])
            ->where('round', $round)
            ->whereIn('competition_id', $competitions->keys())
            ->where(fn($q) => $q->whereIn('home_entry_id', $mine)->orWhereIn('away_entry_id', $mine))
            ->get();

        if ($fixtures->isEmpty()) {
            return [];
        }

        // Liczba odpowiedzi rywali: user_id => zestaw => strona => liczba.
        $rivalUsers = $fixtures->map(fn($f) => ($mine->contains($f->home_entry_id) ? $f->away : $f->home)?->seasonTeam?->user_id)->filter()->unique();
        $slots = MatchdayQuestion::where('matchday_id', $matchday->id)->get(['id', 'competition_type', 'side'])->keyBy('id');
        $counts = [];
        foreach (TipAnswer::where('matchday_id', $matchday->id)->whereIn('user_id', $rivalUsers)->get(['user_id', 'matchday_question_id']) as $answer) {
            $slot = $slots->get($answer->matchday_question_id);
            if ($slot) {
                $counts[$answer->user_id][$slot->competition_type->value][$slot->side->value] = ($counts[$answer->user_id][$slot->competition_type->value][$slot->side->value] ?? 0) + 1;
            }
        }

        $tips = Tip::where('matchday_id', $matchday->id)->whereIn('user_id', $rivalUsers)->get()->keyBy('user_id');
        $scores = $phase === self::PLAYED
            ? TeamScore::where('matchday_id', $matchday->id)->get()->groupBy('season_team_id')
            : collect();

        $out = [];
        foreach ($fixtures as $fixture) {
            $competition = $competitions[$fixture->competition_id];
            $rivalEntry = $mine->contains($fixture->home_entry_id) ? $fixture->away : $fixture->home;
            $rival = $rivalEntry?->seasonTeam;
            $set = $competition->type->questionSet()->value;

            $row = [
                'competition' => $competition->name ?: $competition->type->label(),
                'rival' => $rival?->name ?? Fixture::VIRTUAL_OPPONENT,
                'bot' => (bool) $rival?->is_bot,
                'virtual' => $rival === null,
                'tipped' => null, 'outcome' => null, 'tip' => null, 'offense' => null, 'defense' => null,
                'bonus' => null, 'score' => $fixture->isPlayed() ? $fixture->score() : null,
            ];

            if ($rival && !$rival->is_bot) {
                $tip = $tips->get($rival->user_id);
                $offense = $counts[$rival->user_id][$set][QuestionSide::Offensive->value] ?? 0;
                $defense = $counts[$rival->user_id][$set][QuestionSide::Defensive->value] ?? 0;

                $row['tipped'] = $tip !== null;

                if ($tip && ($phase === self::PLAYED || ($phase === self::CLOSED && $premium))) {
                    $row['tip'] = $tip->score();
                } elseif ($tip && $phase === self::OPEN && $premium) {
                    $row['outcome'] = self::outcome($tip->lech_goals, $tip->opponent_goals);
                }

                if ($phase !== self::OPEN || $premium) {
                    $row['offense'] = $offense;
                    $row['defense'] = $defense;
                }
            }

            // Po wynikach: typ i bonusy z rozliczenia (także boty).
            if ($rival && $phase === self::PLAYED) {
                $score = $scores->get($rival->id)?->first(fn($s) => $s->question_set->value === $set);
                if ($score) {
                    $row['tipped'] = $score->has_tip;
                    $row['tip'] = $score->has_tip ? $score->tip_lech . ':' . $score->tip_opponent : null;
                    $row['bonus'] = __('Offense :offense, defense :defense', ['offense' => $score->offense_bonus, 'defense' => $score->defense_bonus]);
                }
            }

            $out[] = $row;
        }

        return $out;
    }

    /** Rozstrzygnięcie z perspektywy Lecha. */
    private static function outcome(int $lech, int $opponent): string
    {
        return match (true) {
            $lech > $opponent => __('Lech wins'),
            $lech < $opponent => __('Lech loses'),
            default => __('A draw'),
        };
    }
}
