<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Występ zawodnika Lecha w meczu kolejki (raport meczu w dashboard/results).
 *
 * @property int $matchday_id
 * @property int $lech_player_id
 * @property bool $started wyjściowa jedenastka
 * @property int|null $on_minute minuta wejścia z ławki (46 = od przerwy)
 * @property int|null $off_minute minuta zejścia (zmiana albo czerwona kartka), null = do końca
 * @property bool $yellow
 * @property bool $red
 */
class MatchAppearance extends Model
{
    protected $fillable = ['matchday_id', 'lech_player_id', 'started', 'on_minute', 'off_minute', 'yellow', 'red'];

    protected $attributes = ['started' => true, 'yellow' => false, 'red' => false];

    protected function casts(): array
    {
        return [
            'started' => 'boolean',
            'on_minute' => 'integer',
            'off_minute' => 'integer',
            'yellow' => 'boolean',
            'red' => 'boolean',
        ];
    }

    /** @return BelongsTo<Matchday, $this> */
    public function matchday(): BelongsTo
    {
        return $this->belongsTo(Matchday::class);
    }

    /** @return BelongsTo<LechPlayer, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(LechPlayer::class, 'lech_player_id');
    }
}
