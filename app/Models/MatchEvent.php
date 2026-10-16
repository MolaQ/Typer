<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wpis protokołu meczu Lecha (gol z asystą, samobój, kartka, zmiana). Z protokołu MatchReport::store liczy występy.
 *
 * @property int $id
 * @property int $lech_match_id
 * @property string $type goal | own_goal | card | sub
 * @property int $lech_player_id strzelec, ukarany, schodzący albo autor samobója
 * @property int|null $other_player_id asystent albo wchodzący
 * @property string|null $card yellow | second_yellow | red
 * @property int|null $slot punkt osi meczu (MatchReport::OFF_LABELS)
 * @property-read LechPlayer|null $player
 * @property-read LechPlayer|null $other
 */
class MatchEvent extends Model
{
    protected $fillable = ['lech_match_id', 'type', 'lech_player_id', 'other_player_id', 'card', 'slot'];

    protected function casts(): array
    {
        return [
            'lech_player_id' => 'integer',
            'other_player_id' => 'integer',
            'slot' => 'integer',
        ];
    }

    /** @return BelongsTo<LechPlayer, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(LechPlayer::class, 'lech_player_id');
    }

    /** @return BelongsTo<LechPlayer, $this> */
    public function other(): BelongsTo
    {
        return $this->belongsTo(LechPlayer::class, 'other_player_id');
    }
}
