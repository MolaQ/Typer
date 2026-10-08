<?php

namespace App\Actions\Results;

use App\Enums\MatchdayStatus;
use App\Enums\SeasonStatus;
use App\Models\Matchday;
use App\Models\MatchdayQuestion;
use App\Support\Audit;
use App\Support\TipRules;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Admin wpisuje wynik meczu Lecha (po 90 minutach, Lech : rywal) i poprawne odpowiedzi na wszystkie
 * pytania kolejki (regulamin, punkt 11). Kolejka przechodzi w stan "rozegrana" i od razu się przelicza.
 *
 * Zasady:
 *  - tylko w aktywnym sezonie, po godzinie meczu, dla kolejki nieprzełożonej,
 *  - kolejki po kolei: poprzednia musi mieć wynik (puchar potrzebuje zwycięzców poprzedniej rundy),
 *  - poprawka jest możliwa, dopóki następna kolejka nie ma wyniku; przelicza kolejkę od nowa.
 */
class SaveMatchdayResult
{
    public function __construct(private ScoreMatchday $score) {}

    /**
     * @param  array<int|string, mixed>  $correct  matchday_question_id => '1' | '0'
     * @return array{teams: int, fixtures: int, eliminated: int}
     *
     * @throws DomainException
     */
    public function handle(Matchday $matchday, mixed $lechGoals, mixed $opponentGoals, array $correct): array
    {
        self::ensureEditable($matchday);

        $lech = TipRules::goals($lechGoals);
        $opponent = TipRules::goals($opponentGoals);

        if ($lech === null || $opponent === null) {
            throw new DomainException(__('Enter the score as numbers from 0 to :max.', ['max' => TipRules::MAX_GOALS]));
        }

        $slots = MatchdayQuestion::where('matchday_id', $matchday->id)->get();
        $answers = [];

        foreach ($slots as $slot) {
            $answer = TipRules::answer($correct[$slot->id] ?? null);

            if ($answer === null) {
                throw new DomainException(__('Mark the correct answer for every question of this matchday.'));
            }

            $answers[$slot->id] = $answer;
        }

        return DB::transaction(function () use ($matchday, $lech, $opponent, $slots, $answers): array {
            $old = [
                'result' => $matchday->lech_goals !== null ? $matchday->lech_goals . ':' . $matchday->opponent_goals : null,
                'status' => $matchday->status->value,
            ];

            $matchday->update([
                'lech_goals' => $lech,
                'opponent_goals' => $opponent,
                'status' => MatchdayStatus::Played,
            ]);

            foreach ($slots as $slot) {
                if ($slot->correct_answer !== $answers[$slot->id]) {
                    $slot->update(['correct_answer' => $answers[$slot->id]]);
                }
            }

            $stats = $this->score->handle($matchday->fresh());

            Audit::log(
                'matchday.scored',
                null,
                $old,
                ['result' => $lech . ':' . $opponent, 'matches' => $stats['fixtures']],
                __('Matchday :number', ['number' => $matchday->number]) . ' (' . $matchday->season->title . ')',
            );

            return $stats;
        });
    }

    /**
     * Czy można (jeszcze) wpisać wynik tej kolejki?
     *
     * @throws DomainException z powodem, gdy nie
     */
    public static function ensureEditable(Matchday $matchday): void
    {
        if ($matchday->season?->status !== SeasonStatus::Active) {
            throw new DomainException(__('Results can be entered only in the active season.'));
        }

        if ($matchday->status === MatchdayStatus::Postponed) {
            throw new DomainException(__('The match is postponed. Set a new date or choose another match first.'));
        }

        if ($matchday->kickoff_at === null || now()->lt($matchday->kickoff_at)) {
            throw new DomainException(__('The result can be entered after the match starts.'));
        }

        $previous = Matchday::where('season_id', $matchday->season_id)->where('number', $matchday->number - 1)->first();

        if ($previous && $previous->status !== MatchdayStatus::Played) {
            throw new DomainException(__('Enter the result of the previous matchday first.'));
        }

        $nextPlayed = Matchday::where('season_id', $matchday->season_id)
            ->where('number', $matchday->number + 1)
            ->where('status', MatchdayStatus::Played)
            ->exists();

        if ($nextPlayed) {
            throw new DomainException(__('The next matchday already has a result, so this one cannot be changed.'));
        }
    }

    /** To samo co ensureEditable, ale jako tak/nie (do widoków). */
    public static function canEdit(Matchday $matchday): bool
    {
        try {
            self::ensureEditable($matchday);
        } catch (DomainException) {
            return false;
        }

        return true;
    }
}
