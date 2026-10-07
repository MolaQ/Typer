<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uczestnik rozgrywek: zespół z listy przedsezonowej z numerem rozstawienia.
 *
 * @property int $competition_id
 * @property int $season_team_id
 * @property int $seed
 */
class CompetitionEntry extends Model
{
    protected $fillable = ['competition_id', 'season_team_id', 'seed'];

    protected function casts(): array
    {
        return ['seed' => 'integer'];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function seasonTeam(): BelongsTo
    {
        return $this->belongsTo(SeasonTeam::class);
    }
}
