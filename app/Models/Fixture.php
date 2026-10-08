<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mecz w terminarzu rozgrywek. Gospodarz to faworyt (wyżej na liście, niższy numer miejsca).
 *
 * @property int $competition_id
 * @property int $round
 * @property int|null $home_seat
 * @property int|null $away_seat
 * @property int|null $home_entry_id
 * @property int|null $away_entry_id
 */
class Fixture extends Model
{
    protected $fillable = [
        'competition_id', 'round', 'home_seat', 'away_seat', 'home_entry_id', 'away_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'home_seat' => 'integer',
            'away_seat' => 'integer',
        ];
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

    /** Mecz z wirtualnym rywalem (wolny los w lidze szwajcarskiej). */
    public function isBye(): bool
    {
        return $this->home_entry_id !== null && $this->away_entry_id === null && $this->away_seat === null;
    }
}
