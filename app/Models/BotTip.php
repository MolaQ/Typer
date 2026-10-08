<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Losowy typ bota na kolejkę (0-3 : 0-3, z perspektywy Lecha). Boty nie odpowiadają na pytania.
 *
 * @property int $matchday_id
 * @property int $season_team_id
 * @property int $lech_goals
 * @property int $opponent_goals
 */
class BotTip extends Model
{
    /** Największa liczba goli w losowym typie bota. */
    public const MAX_GOALS = 3;

    protected $fillable = ['matchday_id', 'season_team_id', 'lech_goals', 'opponent_goals'];

    protected function casts(): array
    {
        return [
            'lech_goals' => 'integer',
            'opponent_goals' => 'integer',
        ];
    }

    public function matchday(): BelongsTo
    {
        return $this->belongsTo(Matchday::class);
    }

    public function seasonTeam(): BelongsTo
    {
        return $this->belongsTo(SeasonTeam::class);
    }
}
