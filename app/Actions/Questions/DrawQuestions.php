<?php

namespace App\Actions\Questions;

use App\Enums\CompetitionType;
use App\Enums\QuestionSide;
use App\Models\Competition;
use App\Models\Matchday;
use App\Models\MatchdayQuestion;
use App\Models\Question;
use App\Support\Audit;
use App\Support\QuestionDifficulty;
use App\Support\QuestionPicker;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Uzupełnia puste miejsca w zestawach pytań kolejki losowymi pytaniami z banku
 * (regulamin, punkt 4: zestawy różnych typów rozgrywek w tej samej kolejce się nie powtarzają).
 * Istniejące miejsca zostają nietknięte, więc można najpierw ustawić część ręcznie.
 * Liga Legend dostaje w pierwszej kolejności najtrudniejsze pytania (App\Support\QuestionDifficulty).
 * Jeśli w banku brakuje aktywnych pytań, nic się nie zapisuje (transakcja) i admin dostaje komunikat.
 */
class DrawQuestions
{
    /**
     * @param  CompetitionType|null  $type  jeden typ rozgrywek albo null = wszystkie typy sezonu
     * @return int liczba uzupełnionych miejsc
     *
     * @throws DomainException
     */
    public function handle(Matchday $matchday, ?CompetitionType $type = null): int
    {
        if (!$matchday->questionsEditable()) {
            throw new DomainException(__('The questions of this matchday can no longer be changed.'));
        }

        return DB::transaction(function () use ($matchday, $type): int {
            $types = $type ? [$type] : self::typesOfSeason($matchday->season_id);

            // Liga Legend losuje pierwsza, żeby najtrudniejsze pytania nie trafiły wcześniej do innych zestawów.
            usort($types, fn($a, $b) => ($b === CompetitionType::Legends) <=> ($a === CompetitionType::Legends));

            if ($types === []) {
                throw new DomainException(__('Approve the season first: the competitions are created on approval.'));
            }

            $used = MatchdayQuestion::where('matchday_id', $matchday->id)->pluck('question_id')->all();
            $filled = 0;
            $now = now();

            foreach ($types as $competitionType) {
                foreach (QuestionSide::cases() as $side) {
                    $taken = MatchdayQuestion::where('matchday_id', $matchday->id)
                        ->where('competition_type', $competitionType->value)
                        ->where('side', $side->value)
                        ->pluck('position')
                        ->all();

                    $missing = array_values(array_diff(range(1, MatchdayQuestion::PER_SIDE), $taken));

                    if ($missing === []) {
                        continue;
                    }

                    $pool = Question::where('is_active', true)->where('side', $side->value)->pluck('id')->all();
                    $picked = [];

                    // Liga Legend: najpierw losujemy spośród najtrudniejszych pytań (najmniej poprawnych odpowiedzi),
                    // z puli dwa razy większej niż zestaw, żeby zestawy nie były co kolejkę identyczne.
                    if ($competitionType === CompetitionType::Legends) {
                        $hard = QuestionDifficulty::hardest(MatchdayQuestion::PER_SIDE * 2, $side->value)
                            ->map(fn($row) => $row['question']->id)->all();
                        $picked = QuestionPicker::pick($hard, $used, count($missing));
                    }

                    $picked = array_merge($picked, QuestionPicker::pick($pool, array_merge($used, $picked), count($missing) - count($picked)));

                    if (count($picked) < count($missing)) {
                        throw new DomainException(__('Not enough :side questions in the bank. Add more active questions.', ['side' => mb_strtolower($side->label())]));
                    }

                    $rows = [];
                    foreach ($missing as $index => $position) {
                        $rows[] = [
                            'matchday_id' => $matchday->id,
                            'competition_type' => $competitionType->value,
                            'side' => $side->value,
                            'position' => $position,
                            'question_id' => $picked[$index],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    MatchdayQuestion::insert($rows);

                    $used = array_merge($used, $picked);
                    $filled += count($rows);
                }
            }

            if ($filled > 0) {
                Audit::log(
                    'matchday_questions.drawn',
                    null,
                    [],
                    ['slots' => $filled, 'type' => $type?->label() ?? __('All competitions')],
                    __('Matchday :number', ['number' => $matchday->number]) . ' (' . $matchday->season->title . ')',
                );
            }

            return $filled;
        });
    }

    /**
     * Zestawy pytań potrzebne w sezonie: po jednym na typ rozgrywek, które sezon faktycznie ma.
     * Wszystkie 10 lig i Liga podwórkowa mają jeden wspólny zestaw (League).
     *
     * @return array<int, CompetitionType>
     */
    public static function typesOfSeason(int $seasonId): array
    {
        $present = Competition::where('season_id', $seasonId)->pluck('type')->unique()
            ->map(fn($t) => ($t instanceof CompetitionType ? $t : CompetitionType::from($t))->questionSet())->all();

        return array_values(array_filter(CompetitionType::cases(), fn($t) => in_array($t, $present, true)));
    }
}