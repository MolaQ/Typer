<?php

namespace App\Models;

use App\Enums\CompetitionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zawodnik Lecha wystawiony przez klub (gracz albo bot) w kolejce do jednego typu rozgrywek (Support\Squad).
 *
 * @property int $matchday_id
 * @property int|null $user_id
 * @property int|null $bot_id
 * @property CompetitionType $score_set
 * @property int $lech_player_id
 */
class DesignatedPlayer extends Model
{
    protected $fillable = ['matchday_id', 'user_id', 'bot_id', 'score_set', 'lech_player_id'];

    protected function casts(): array
    {
        return [
            'score_set' => CompetitionType::class,
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
