<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mecz w terminarzu rozgrywek. Gospodarz to zespół wyżej na liście (faworyt),
 * czyli o niższym numerze rozstawienia.
 *
 * @property int $competition_id
 * @property int $round
 * @property int $home_entry_id
 * @property int $away_entry_id
 */
class Fixture extends Model
{
    protected $fillable = ['competition_id', 'round', 'home_entry_id', 'away_entry_id'];

    protected function casts(): array
    {
        return ['round' => 'integer'];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function home(): BelongsTo
    {
        return $this->belongsTo(CompetitionEntry::class, 'home_entry_id');
    }

    public function away(): BelongsTo
    {
        return $this->belongsTo(CompetitionEntry::class, 'away_entry_id');
    }
}
