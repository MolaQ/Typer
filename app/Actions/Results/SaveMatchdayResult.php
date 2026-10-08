<?php

namespace App\Actions\Results;

use App\Actions\Competitions\DrawNextSwissRound;
use App\Actions\Seasons\AwardHallOfFame;
use App\Enums\MatchdayStatus;
use App\Enums\SeasonStatus;
use App\Models\Matchday;
use App\Models\MatchdayQuestion;
use App\Models\TipAnswer;
use App\Support\Audit;
use App\Support\TipRules;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Admin wpisuje wynik meczu Lecha (po 90 minutach, Lech : rywal) i poprawne odpowiedzi na pytania kolejki
 * (regulamin, punkt 11). Wynik i każda odpowiedź zapisują się od razu (saveScore, saveAnswer), a przeliczenie
 * (recalculate) ustawia kolejkę na „rozegrana” i liczy punkty. Kolejka już przeliczona przelicza się
 * od nowa po każdej zmianie (robi to strona wyników).
 *
 * Zasady:
 *  - tylko w aktywnym sezonie, po godzinie meczu, dla kolejki nieprzełożonej,
 *  - kolejki po kolei: poprzednia musi mieć wynik (puchar potrzebuje zwycięzców poprzedniej rundy),
 *  - poprawka jest możliwa, dopóki następna kolejka nie ma wyniku,
 *  - pytanie bez poprawnej odpowiedzi w chwili przeliczenia jest anulowane: odpowiedzi wszystkich graczy
 *    na nie są usuwane, jakby nigdy nie padły.
 */
class SaveMatchdayResult
{
    public function __construct(private ScoreMatchday $score) {}

    /**
     * Zapis wyniku i odpowiedzi naraz, z przeliczeniem.
     *
     * @param  array<int|string, mixed>  $correct  matchday_question_id => '1' | '0' | '' (pytanie anulowane)
     * @return array{teams: int, fixtures: int, eliminated: int}
     *
     * @throws DomainException
     */
    public function handle(Matchday $matchday, mixed $lechGoals, mixed $opponentGoals, array $correct): array
    {
        $this->saveScore($matchday, $lechGoals, $opponentGoals);

        foreach (MatchdayQuestion::where('matchday_id', $matchday->id)->pluck('id') as $slotId) {
            $this->saveAnswer($matchday, $slotId, $correct[$slotId] ?? null);
        }

        return $this->recalculate($matchday->fresh());
    }

    /**
     * Wynik meczu (bez przeliczenia).
     *
     * @throws DomainException
     */
    public function saveScore(Matchday $matchday, mixed $lechGoals, mixed $opponentGoals): void
    {
        self::ensureEditable($matchday);

        $lech = TipRules::goals($lechGoals);
        $opponent = TipRules::goals($opponentGoals);

        if ($lech === null || $opponent === null) {
            throw new DomainException(__('Enter the score as numbers from 0 to :max.', ['max' => TipRules::MAX_GOALS]));
        }

        if ($matchday->lech_goals === $lech && $matchday->opponent_goals === $opponent) {
            return;
        }

        $old = $matchday->lech_goals !== null ? $matchday->lech_goals . ':' . $matchday->opponent_goals : null;
        $matchday->update(['lech_goals' => $lech, 'opponent_goals' => $opponent]);

        Audit::log('matchday.result', null, ['result' => $old], ['result' => $lech . ':' . $opponent], $this->label($matchday));
    }

    /**
     * Poprawna odpowiedź na jedno pytanie (null albo '' = brak, czyli pytanie anulowane przy przeliczeniu).
     *
     * @throws DomainException
     */
    public function saveAnswer(Matchday $matchday, int $slotId, mixed $value): void
    {
        self::ensureEditable($matchday);

        $slot = MatchdayQuestion::where('matchday_id', $matchday->id)->findOrFail($slotId);
        $answer = TipRules::answer($value);

        if ($slot->correct_answer !== $answer) {
            $slot->update(['correct_answer' => $answer]);
        }
    }

    /**
     * Przeliczenie kolejki: status „rozegrana”, usunięcie odpowiedzi na pytania anulowane, punkty i mecze.
     *
     * @return array{teams: int, fixtures: int, eliminated: int, swiss: int}
     *
     * @throws DomainException
     */
    public function recalculate(Matchday $matchday): array
    {
        self::ensureEditable($matchday);

        if ($matchday->lech_goals === null || $matchday->opponent_goals === null) {
            throw new DomainException(__('Enter the result of the Lech match first.'));
        }

        return DB::transaction(function () use ($matchday): array {
            $wasPlayed = $matchday->status === MatchdayStatus::Played;
            $matchday->update(['status' => MatchdayStatus::Played]);

            // Pytania anulowane: usuwamy odpowiedzi graczy, nie liczą się do niczego.
            $cancelled = MatchdayQuestion::where('matchday_id', $matchday->id)->whereNull('correct_answer')->pluck('id');
            if ($cancelled->isNotEmpty()) {
                TipAnswer::whereIn('matchday_question_id', $cancelled)->delete();
            }

            $stats = $this->score->handle($matchday->fresh());

            // Liga podwórkowa: pary kolejnej rundy losujemy od razu według nowej klasyfikacji.
            $stats['swiss'] = app(DrawNextSwissRound::class)->afterMatchday($matchday->fresh());

            // Hall of Fame na bieżąco: punkty za mecze sezonu (tytuły i trofea dopiero po jego zakończeniu).
            app(AwardHallOfFame::class)->handle($matchday->season);

            Audit::log(
                'matchday.scored',
                null,
                ['status' => $wasPlayed ? MatchdayStatus::Played->value : 'pending'],
                ['result' => $matchday->lech_goals . ':' . $matchday->opponent_goals, 'matches' => $stats['fixtures'], 'cancelled_questions' => $cancelled->count()],
                $this->label($matchday),
            );

            return $stats;
        });
    }

    private function label(Matchday $matchday): string
    {
        return __('Matchday :number', ['number' => $matchday->number]) . ' (' . $matchday->season->title . ')';
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
