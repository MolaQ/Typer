<?php

namespace App\Models;

use App\Enums\MatchdayStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kolejka sezonu z jednym rzeczywistym meczem Lecha.
 *
 * @property int $season_id
 * @property int $number
 * @property string|null $opponent
 * @property bool $is_home
 * @property string|null $competition
 * @property \Illuminate\Support\Carbon|null $kickoff_at
 * @property MatchdayStatus $status
 */
class Matchday extends Model
{
    /** Liczba kolejek w sezonie (regulamin: 9). */
    public const PER_SEASON = 9;

    protected $fillable = [
        'season_id',
        'number',
        'opponent',
        'is_home',
        'competition',
        'kickoff_at',
        'status',
        'lech_goals',
        'opponent_goals',
    ];

    protected $attributes = [
        'status' => 'planned',
        'is_home' => true,
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'is_home' => 'boolean',
            'kickoff_at' => 'datetime',
            'status' => MatchdayStatus::class,
            'lech_goals' => 'integer',
            'opponent_goals' => 'integer',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** Kolejka jest uzupełniona, gdy ma rywala i termin. */
    public function isFilled(): bool
    {
        return filled($this->opponent) && $this->kickoff_at !== null;
    }

    /**
     * Czy użytkownicy mogą jeszcze typować?
     * Typowanie jest otwarte do godziny pierwszego gwizdka (regulamin, punkt 3).
     * Wykorzystamy to w etapie 10 (formularz typowania).
     */
    public function isOpenForTips(): bool
    {
        return $this->status === MatchdayStatus::Planned
            && $this->kickoff_at !== null
            && now()->lt($this->kickoff_at);
    }

    /** Zestawy pytań bonusowych tej kolejki (wszystkie typy rozgrywek). */
    public function questions(): HasMany
    {
        return $this->hasMany(MatchdayQuestion::class)->orderBy('competition_type')->orderBy('side')->orderBy('position');
    }

    /**
     * Czy pytania tej kolejki można jeszcze zmieniać?
     * Tak, dopóki mecz się nie zaczął (potem użytkownicy mają już odpowiedzi) i kolejka jest zaplanowana.
     */
    public function questionsEditable(): bool
    {
        return $this->status === MatchdayStatus::Planned
            && ($this->kickoff_at === null || now()->lt($this->kickoff_at));
    }

    /** $matchday->fixture -> "Lech Poznań – Legia" albo "Legia – Lech Poznań". */
    protected function fixture(): Attribute
    {
        return Attribute::get(function () {
            if (!filled($this->opponent)) {
                return '—';
            }

            return $this->is_home
                ? 'Lech Poznań – ' . $this->opponent
                : $this->opponent . ' – Lech Poznań';
        });
    }
}