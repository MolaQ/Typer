<?php

namespace App\Models;

use App\Enums\CompetitionType;
use App\Enums\League;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rozgrywki w ramach sezonu (na razie: jedna liga poziomów 1-10).
 *
 * @property int $season_id
 * @property CompetitionType $type
 * @property int|null $tier
 * @property string $name
 */
class Competition extends Model
{
    protected $fillable = ['season_id', 'type', 'tier', 'name'];

    protected function casts(): array
    {
        return [
            'type' => CompetitionType::class,
            'tier' => 'integer',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** Uczestnicy posortowani po rozstawieniu. */
    public function entries(): HasMany
    {
        return $this->hasMany(CompetitionEntry::class)->orderBy('seed');
    }

    public function fixtures(): HasMany
    {
        return $this->hasMany(Fixture::class)->orderBy('round')->orderBy('id');
    }

    /** Dla lig: odpowiadający poziom z enuma League. */
    protected function league(): Attribute
    {
        return Attribute::get(fn () => $this->tier ? League::tryFrom($this->tier) : null);
    }
}
