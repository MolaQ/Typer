<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Występ zawodnika Lecha w meczu (raport meczu, Support\MatchReport). Wejście i zejście to punkty osi meczu
 * (MatchReport::SLOTS), kolumny clean_sheet, play_time, rest_hours i experience wylicza MatchReport::store.
 *
 * @property int $id
 * @property int $lech_match_id
 * @property int $lech_player_id
 * @property bool $started wyjściowa jedenastka
 * @property int|null $on_slot null = rezerwowy bez zaznaczonego wejścia
 * @property int $off_slot
 * @property int $goals
 * @property int $assists
 * @property int $own_goals
 * @property int $yellow 0, 1 albo 2 (dwie żółte)
 * @property bool $red
 * @property bool|null $clean_sheet
 * @property float $play_time
 * @property int $rest_hours
 * @property float $experience
 * @property-read LechPlayer|null $player
 * @property-read LechMatch|null $lechMatch
 */
class MatchAppearance extends Model
{
    protected $fillable = [
        'lech_match_id', 'lech_player_id', 'started', 'on_slot', 'off_slot', 'goals', 'assists', 'own_goals', 'yellow', 'red',
        'clean_sheet', 'play_time', 'rest_hours', 'experience',
    ];

    protected $attributes = ['started' => true, 'on_slot' => 0, 'off_slot' => 4, 'goals' => 0, 'assists' => 0, 'own_goals' => 0, 'yellow' => 0, 'red' => false];

    protected function casts(): array
    {
        return [
            'started' => 'boolean',
            'on_slot' => 'integer',
            'off_slot' => 'integer',
            'goals' => 'integer',
            'assists' => 'integer',
            'own_goals' => 'integer',
            'yellow' => 'integer',
            'red' => 'boolean',
            'clean_sheet' => 'boolean',
            'play_time' => 'float',
            'rest_hours' => 'integer',
            'experience' => 'float',
        ];
    }

    /** @return BelongsTo<LechMatch, $this> */
    public function lechMatch(): BelongsTo
    {
        return $this->belongsTo(LechMatch::class);
    }

    /** @return BelongsTo<LechPlayer, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(LechPlayer::class, 'lech_player_id');
    }
}
