<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Enums\QuestionSide;
use App\Models\Matchday;
use App\Models\MatchdayQuestion;
use App\Models\Tip;
use Illuminate\Support\Facades\DB;

/**
 * Okno meczu Lecha na stronie wyników: pytania kolejki z poprawnymi odpowiedziami i ciekawostki o typach graczy.
 * Liczymy tylko typy wpisane przez graczy (bez domyślnych typów premium i bez botów).
 * Przed pierwszym gwizdkiem pytania są ukryte (żeby nie podpowiadać), a statystyki typów pokazujemy tylko premium.
 */
final class MatchdayInsights
{
    /**
     * Statystyki typów kolejki. Po wyniku dochodzą trafienia i najczęstsze wyniki.
     *
     * @return array{count: int, lech: int, draw: int, opponent: int, avg_lech: ?float, avg_opponent: ?float,
     *   exact: ?int, outcome: ?int, diff: ?int, popular: array<int, array{score: string, count: int}>, exact_count: ?int}
     */
    public static function tips(Matchday $matchday): array
    {
        $tips = Tip::where('matchday_id', $matchday->id)->where('is_default', false)->get(['lech_goals', 'opponent_goals']);
        $count = $tips->count();
        $played = $matchday->lech_goals !== null && $matchday->opponent_goals !== null;

        $out = [
            'count' => $count,
            'lech' => $tips->filter(fn (Tip $t) => $t->lech_goals > $t->opponent_goals)->count(),
            'draw' => $tips->filter(fn (Tip $t) => $t->lech_goals === $t->opponent_goals)->count(),
            'opponent' => $tips->filter(fn (Tip $t) => $t->lech_goals < $t->opponent_goals)->count(),
            'avg_lech' => $count > 0 ? round((float) $tips->avg('lech_goals'), 1) : null,
            'avg_opponent' => $count > 0 ? round((float) $tips->avg('opponent_goals'), 1) : null,
            'exact' => null, 'outcome' => null, 'diff' => null, 'exact_count' => null,
            // Najczęstsze typy (do trzech), od najpopularniejszego.
            'popular' => $tips->countBy(fn (Tip $t) => $t->lech_goals.':'.$t->opponent_goals)
                ->sortDesc()
                ->take(3)
                ->map(fn (int $n, string $score) => ['score' => $score, 'count' => $n])
                ->values()
                ->all(),
        ];

        if ($played && $count > 0) {
            $results = $tips->map(fn (Tip $t) => Scoring::tip($t->lech_goals, $t->opponent_goals, (int) $matchday->lech_goals, (int) $matchday->opponent_goals));
            $out['exact_count'] = $results->where('exact', true)->count();
            $out['exact'] = self::percent($out['exact_count'], $count);
            $out['outcome'] = self::percent($results->where('outcome', true)->count(), $count);
            $out['diff'] = self::percent($results->where('diff', true)->count(), $count);
        }

        return $out;
    }

    /**
     * Pytania kolejki w zestawach (ligi i podwórkowa, puchar, puchary europejskie, Liga Legend), z poprawną odpowiedzią
     * (null: przed wynikiem albo pytanie anulowane), liczbą odpowiedzi i odsetkiem poprawnych.
     *
     * @return array<string, array{label: string, sides: array<string, list<array{text: string, correct: ?bool, answers: int, yes: int, right: ?int}>>}>
     */
    public static function questions(Matchday $matchday): array
    {
        $slots = MatchdayQuestion::with('question:id,text')->where('matchday_id', $matchday->id)
            ->orderBy('competition_type')->orderBy('side')->orderBy('position')->get();

        // id miejsca => [liczba odpowiedzi, liczba „tak”].
        $counts = DB::table('tip_answers')->where('matchday_id', $matchday->id)
            ->selectRaw('matchday_question_id, count(*) as answers, sum(case when answer then 1 else 0 end) as yes')
            ->groupBy('matchday_question_id')
            ->get()
            ->keyBy('matchday_question_id');

        $out = [];
        foreach ($slots as $slot) {
            $type = $slot->competition_type;
            $row = $counts->get($slot->id);
            $answers = (int) ($row->answers ?? 0);
            $yes = (int) ($row->yes ?? 0);
            $right = $slot->correct_answer === null ? null : ($slot->correct_answer ? $yes : $answers - $yes);

            $out[$type->value] ??= ['label' => $type->questionSetLabel(), 'sides' => [QuestionSide::Offensive->value => [], QuestionSide::Defensive->value => []]];
            $out[$type->value]['sides'][$slot->side->value][] = [
                'text' => (string) $slot->question?->getAttribute('text'),
                'correct' => $slot->correct_answer,
                'answers' => $answers,
                'yes' => $yes,
                'right' => $right === null ? null : self::percent($right, $answers),
            ];
        }

        // Kolejność zestawów jak na stronie wyników: ligi, puchar, puchary europejskie, Liga Legend.
        $order = [CompetitionType::League->value, CompetitionType::Cup->value, CompetitionType::Champions->value, CompetitionType::Legends->value];
        $rank = fn (string $key): int => ($index = array_search($key, $order, true)) === false ? 99 : (int) $index;
        uksort($out, fn (string $a, string $b) => $rank($a) <=> $rank($b));

        return $out;
    }

    /** Procent zaokrąglony do całości, null przy braku danych. */
    private static function percent(int $part, int $total): ?int
    {
        return $total > 0 ? (int) round($part / $total * 100) : null;
    }
}
