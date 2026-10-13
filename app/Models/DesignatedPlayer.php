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
 * @property bool|null $counted null = jeszcze nie rozliczony, false = przepadł (blokada)
 * @property int $minutes
 * @property int $goals
 * @property int $assists
 * @property int $own_goals
 * @property bool|null $clean_sheet
 * @property bool $yellow
 * @property bool $red
 * @property int $attack
 * @property int $defense
 * @property int $experience
 * @property int $lock
 */
class DesignatedPlayer extends Model
{
    protected $fillable = [
        'matchday_id', 'user_id', 'bot_id', 'score_set', 'lech_player_id',
        'counted', 'minutes', 'goals', 'assists', 'own_goals', 'clean_sheet', 'yellow', 'red', 'attack', 'defense', 'experience', 'lock',
    ];

    protected function casts(): array
    {
        return [
            'score_set' => CompetitionType::class,
            'counted' => 'boolean',
            'clean_sheet' => 'boolean',
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
