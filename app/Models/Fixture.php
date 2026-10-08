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
 * @property int|null $home_goals
 * @property int|null $away_goals
 * @property int|null $winner_entry_id
 * @property bool $decided_by_time
 */
class Fixture extends Model
{
    protected $fillable = [
        'competition_id', 'round', 'home_seat', 'away_seat', 'home_entry_id', 'away_entry_id',
        'home_goals', 'away_goals', 'winner_entry_id', 'decided_by_time',
    ];

    /** Nazwa wirtualnego rywala w Lidze podwórkowej (wolny los). */
    public const VIRTUAL_OPPONENT = 'Lech Poznań';

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'home_seat' => 'integer',
            'away_seat' => 'integer',
            'home_goals' => 'integer',
            'away_goals' => 'integer',
            'decided_by_time' => 'boolean',
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

    public function winner(): BelongsTo
    {
        return $this->belongsTo(CompetitionEntry::class, 'winner_entry_id');
    }

    /** Czy mecz ma już wynik? */
    public function isPlayed(): bool
    {
        return $this->home_goals !== null && $this->away_goals !== null;
    }

    /** "2:1", "1:1 (k)" przy awansie po czasie typu albo "–" przed wynikiem. */
    public function score(): string
    {
        if (!$this->isPlayed()) {
            return '–';
        }

        return $this->home_goals . ':' . $this->away_goals . ($this->decided_by_time ? ' ' . __('(pen.)') : '');
    }

    /** Mecz z wirtualnym rywalem (wolny los w lidze szwajcarskiej). */
    public function isBye(): bool
    {
        return $this->home_entry_id !== null && $this->away_entry_id === null && $this->away_seat === null;
    }
}
