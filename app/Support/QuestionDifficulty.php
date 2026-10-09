<?php

namespace App\Support;

use App\Enums\MatchdayStatus;
use App\Models\Question;
use App\Models\TipAnswer;
use Illuminate\Support\Collection;

/**
 * Trudność pytań z banku: jaki odsetek odpowiedzi graczy był poprawny (tylko rozliczone kolejki).
 * Najtrudniejsze pytania pokazujemy w statystykach premium, a losowanie zestawów Ligi Legend
 * bierze je w pierwszej kolejności (App\Actions\Questions\DrawQuestions).
 */
final class QuestionDifficulty
{
    /** Minimalna liczba odpowiedzi, żeby odsetek był wiarygodny. */
    public const MIN_ANSWERS = 10;

    /**
     * Statystyki pytań: id pytania => [answers, correct, rate (0-100)].
     *
     * @return Collection<int, array{answers: int, correct: int, rate: float}>
     */
    public static function rates(): Collection
    {
        return TipAnswer::query()
            ->join('matchday_questions', 'matchday_questions.id', '=', 'tip_answers.matchday_question_id')
            ->join('matchdays', 'matchdays.id', '=', 'matchday_questions.matchday_id')
            ->where('matchdays.status', MatchdayStatus::Played->value)
            ->whereNotNull('matchday_questions.correct_answer')
            ->groupBy('matchday_questions.question_id')
            ->selectRaw('matchday_questions.question_id as question_id, count(*) as answers, sum(case when tip_answers.answer = matchday_questions.correct_answer then 1 else 0 end) as correct')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->question_id => [
                'answers' => (int) $row->answers,
                'correct' => (int) $row->correct,
                'rate' => $row->answers > 0 ? round($row->correct / $row->answers * 100, 1) : 0.0,
            ]]);
    }

    /**
     * Najtrudniejsze aktywne pytania (najniższy odsetek poprawnych odpowiedzi), opcjonalnie z jednej strony.
     *
     * @return Collection<int, array{question: Question, answers: int, correct: int, rate: float}>
     */
    public static function hardest(int $limit, ?string $side = null): Collection
    {
        $rates = self::rates()->filter(fn ($r) => $r['answers'] >= self::MIN_ANSWERS);

        if ($rates->isEmpty()) {
            return collect();
        }

        $questions = Question::whereIn('id', $rates->keys())
            ->where('is_active', true)
            ->when($side, fn ($q) => $q->where('side', $side))
            ->get()
            ->keyBy('id');

        return $rates->only($questions->keys()->all())
            ->sortBy([['rate', 'asc'], ['answers', 'desc']])
            ->take($limit)
            ->map(fn ($r, $id) => ['question' => $questions[$id]] + $r)
            ->values();
    }
}
