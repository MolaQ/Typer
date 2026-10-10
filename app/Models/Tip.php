<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Typ gracza na wynik meczu Lecha (Lech – rywal, niezależnie od tego, kto gra u siebie).
 *
 * @property int $matchday_id
 * @property int $user_id
 * @property int $lech_goals
 * @property int $opponent_goals
 * @property bool $is_default domyślny typ premium wpisany przy przeliczeniu kolejki
 * @property Carbon $saved_at
 */
class Tip extends Model
{
    protected $fillable = ['matchday_id', 'user_id', 'lech_goals', 'opponent_goals', 'is_default', 'saved_at'];

    /**
     * Zapis dat z mikrosekundami: bez tego Eloquent obcina saved_at do pełnych sekund, a w pucharze remis
     * rozstrzyga wcześniejszy zapis typu (regulamin, punkt 7.3), więc typy z tej samej sekundy wyglądałyby na równe.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'lech_goals' => 'integer',
            'opponent_goals' => 'integer',
            'is_default' => 'boolean',
            'saved_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function matchday(): BelongsTo
    {
        return $this->belongsTo(Matchday::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** "2:1" – wynik z perspektywy Lecha. */
    public function score(): string
    {
        return $this->lech_goals.':'.$this->opponent_goals;
    }
}
