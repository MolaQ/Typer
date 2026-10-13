<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gol w meczu kolejki (raport meczu w dashboard/results), także z dogrywki.
 * Gol Lecha: strzelec i asystent z kadry (strzelec null = samobój rywala). Gol rywala: scorer_id = samobój zawodnika Lecha.
 *
 * @property int $matchday_id
 * @property string $side lech | opponent
 * @property int|null $minute
 * @property int|null $scorer_id
 * @property int|null $assist_id
 */
class MatchGoal extends Model
{
    public const LECH = 'lech';

    public const OPPONENT = 'opponent';

    protected $fillable = ['matchday_id', 'side', 'minute', 'scorer_id', 'assist_id'];

    protected function casts(): array
    {
        return [
            'minute' => 'integer',
            'scorer_id' => 'integer',
            'assist_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Matchday, $this> */
    public function matchday(): BelongsTo
    {
        return $this->belongsTo(Matchday::class);
    }

    /** @return BelongsTo<LechPlayer, $this> */
    public function scorer(): BelongsTo
    {
        return $this->belongsTo(LechPlayer::class, 'scorer_id');
    }

    /** @return BelongsTo<LechPlayer, $this> */
    public function assist(): BelongsTo
    {
        return $this->belongsTo(LechPlayer::class, 'assist_id');
    }
}
