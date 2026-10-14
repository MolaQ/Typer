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
 * @property float $play_time czas gry w połowach
 * @property int $goals
 * @property int $assists
 * @property int $own_goals
 * @property bool|null $clean_sheet
 * @property int $yellow 0-2
 * @property bool $red
 * @property int $attack
 * @property int $defense
 * @property float $experience
 * @property int $rest_hours
 */
class DesignatedPlayer extends Model
{
    protected $fillable = [
        'matchday_id', 'user_id', 'bot_id', 'score_set', 'lech_player_id',
        'counted', 'play_time', 'goals', 'assists', 'own_goals', 'clean_sheet', 'yellow', 'red', 'attack', 'defense', 'experience', 'rest_hours',
    ];

    protected function casts(): array
    {
        return [
            'score_set' => CompetitionType::class,
            'counted' => 'boolean',
            'clean_sheet' => 'boolean',
            'play_time' => 'float',
            'yellow' => 'integer',
            'red' => 'boolean',
            'experience' => 'float',
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
