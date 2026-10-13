<?php

namespace App\Actions\Tips;

use App\Enums\SeasonStatus;
use App\Models\Matchday;
use App\Models\SeasonTeam;
use App\Models\Tip;
use App\Models\User;
use App\Support\Players;
use App\Support\TipRules;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Zapisuje typ gracza (wynik meczu Lecha).
 *
 * Zasady:
 *  - typować może tylko gracz (rola, bez bana), będący na liście zespołów aktywnego sezonu,
 *  - tylko do godziny pierwszego gwizdka (Matchday::isOpenForTips),
 *  - saved_at zmienia się wyłącznie, gdy typ faktycznie się zmienił
 *    (ponowne zapisanie tych samych danych nie psuje pierwszeństwa w remisie pucharu),
 *  - każdy zapis zmiany dostaje kolejny numer z dziennika tip_saves (save_order): liczy się ostatni zapis,
 *    a mniejszy numer wygrywa remisy tam, gdzie rozstrzyga czas typu (puchary, Liga Legend).
 */
class SaveTip
{
    /**
     * @throws DomainException gdy typowanie jest niedozwolone lub dane są błędne
     */
    public function handle(User $user, Matchday $matchday, mixed $lechGoals, mixed $opponentGoals): Tip
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

        return DB::transaction(function () use ($user, $matchday, $lech, $opponent) {
            $tip = Tip::where('matchday_id', $matchday->id)->where('user_id', $user->id)->lockForUpdate()->first();

            $changed = ! $tip
                || $tip->lech_goals !== $lech
                || $tip->opponent_goals !== $opponent;

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

            $tip->fill(['lech_goals' => $lech, 'opponent_goals' => $opponent, 'saved_at' => $savedAt, 'save_order' => $order])->save();

            return $tip;
        });
    }
}
