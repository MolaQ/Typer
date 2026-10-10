<?php

namespace App\Actions\Tips;

use App\Enums\SeasonStatus;
use App\Models\Matchday;
use App\Models\MatchdayQuestion;
use App\Models\SeasonTeam;
use App\Models\Tip;
use App\Models\TipAnswer;
use App\Models\User;
use App\Support\PlayerCompetitions;
use App\Support\Players;
use App\Support\TipRules;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Zapisuje typ gracza i jego odpowiedzi na pytania bonusowe.
 *
 * Zasady:
 *  - typować może tylko gracz (rola, bez bana), będący na liście zespołów aktywnego sezonu,
 *  - tylko do godziny pierwszego gwizdka (Matchday::isOpenForTips),
 *  - gracz odpowiada tylko na zestawy typów rozgrywek, w których gra,
 *  - saved_at zmienia się wyłącznie, gdy typ lub odpowiedzi faktycznie się zmieniły
 *    (ponowne zapisanie tych samych danych nie psuje pierwszeństwa w remisie pucharu),
 *  - każdy zapis zmiany dostaje kolejny numer z dziennika tip_saves (save_order): liczy się ostatni zapis,
 *    a mniejszy numer wygrywa remisy tam, gdzie rozstrzyga czas typu (puchary, Liga Legend).
 */
class SaveTip
{
    /**
     * @param  array<int|string, mixed>  $answers  matchday_question_id => '1' | '0' | ''
     *
     * @throws DomainException gdy typowanie jest niedozwolone lub dane są błędne
     */
    public function handle(User $user, Matchday $matchday, mixed $lechGoals, mixed $opponentGoals, array $answers): Tip
    {
        if (! Players::canPlay($user)) {
            throw new DomainException(__('Your account cannot take part in the game.'));
        }

        $season = $matchday->season;

        if (! $season || $season->status !== SeasonStatus::Active) {
            throw new DomainException(__('Tipping is possible only in the active season.'));
        }

        if (! $matchday->isOpenForTips()) {
            throw new DomainException(__('Tipping for this matchday is closed.'));
        }

        $onList = SeasonTeam::where('season_id', $season->id)->where('user_id', $user->id)->exists();

        if (! $onList) {
            throw new DomainException(__('You are not on the team list of this season.'));
        }

        $lech = TipRules::goals($lechGoals);
        $opponent = TipRules::goals($opponentGoals);

        if ($lech === null || $opponent === null) {
            throw new DomainException(__('Enter the score as numbers from 0 to :max.', ['max' => TipRules::MAX_GOALS]));
        }

        // Miejsca, na które ten gracz może odpowiadać (zestawy jego typów rozgrywek).
        $types = array_map(fn ($t) => $t->value, PlayerCompetitions::types($user->id, $season->id, $matchday->number));
        $allowedIds = MatchdayQuestion::where('matchday_id', $matchday->id)
            ->whereIn('competition_type', $types)
            ->pluck('id')
            ->all();

        $new = [];
        foreach ($allowedIds as $id) {
            $answer = TipRules::answer($answers[$id] ?? null);
            if ($answer !== null) {
                $new[$id] = $answer;
            }
        }

        return DB::transaction(function () use ($user, $matchday, $lech, $opponent, $new, $allowedIds) {
            $tip = Tip::where('matchday_id', $matchday->id)->where('user_id', $user->id)->lockForUpdate()->first();

            // Porównujemy tylko odpowiedzi z zestawów, na które gracz może teraz odpowiadać.
            $old = TipAnswer::where('matchday_id', $matchday->id)->where('user_id', $user->id)
                ->whereIn('matchday_question_id', $allowedIds)
                ->pluck('answer', 'matchday_question_id')
                ->map(fn ($a) => (bool) $a)
                ->all();

            $changed = ! $tip
                || $tip->lech_goals !== $lech
                || $tip->opponent_goals !== $opponent
                || $old != $new;

            if (! $changed) {
                return $tip;
            }

            $tip ??= new Tip(['matchday_id' => $matchday->id, 'user_id' => $user->id]);
            $savedAt = now();
            $order = DB::table('tip_saves')->insertGetId([
                'matchday_id' => $matchday->id,
                'user_id' => $user->id,
                'created_at' => $savedAt->format('Y-m-d H:i:s.u'),
            ]);

            $tip->fill(['lech_goals' => $lech, 'opponent_goals' => $opponent, 'saved_at' => $savedAt, 'save_order' => $order, 'is_default' => false])->save();

            // Usuń odpowiedzi wyczyszczone przez gracza i zapisz pozostałe. Odpowiedzi z rozgrywek, z których odpadł,
            // zostają (nie liczą się, ale służą statystyce pytań i wracają do gry, gdy przeliczenie zmieni awans).
            TipAnswer::where('matchday_id', $matchday->id)->where('user_id', $user->id)
                ->whereIn('matchday_question_id', $allowedIds)
                ->whereNotIn('matchday_question_id', array_keys($new))
                ->delete();

            foreach ($new as $questionSlotId => $answer) {
                TipAnswer::updateOrCreate(
                    ['user_id' => $user->id, 'matchday_question_id' => $questionSlotId],
                    ['matchday_id' => $matchday->id, 'answer' => $answer],
                );
            }

            return $tip;
        });
    }
}
