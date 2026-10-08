<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Miejsce zespołu w tabeli końcowej rozgrywek (zapis przy zakończeniu sezonu).
 *
 * @property int $season_id
 * @property int $competition_id
 * @property int $season_team_id
 * @property int|null $user_id
 * @property int|null $bot_id
 * @property int $place
 * @property array|null $stats
 */
class FinalStanding extends Model
{
    protected $fillable = ['season_id', 'competition_id', 'season_team_id', 'user_id', 'bot_id', 'place', 'stats'];

    protected function casts(): array
    {
        return [
            'place' => 'integer',
            'stats' => 'array',
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function seasonTeam(): BelongsTo
    {
        return $this->belongsTo(SeasonTeam::class);
    }
}
