<?php

namespace App\Models;

use App\Enums\League;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jedna pozycja na liście przedsezonowej: zespół gracza albo bot z puli botów.
 *
 * @property int $season_id
 * @property int $position
 * @property int|null $user_id
 * @property int|null $bot_id
 * @property int|null $previous_id miejsce z poprzedniego sezonu, z którego powstał ten wpis
 */
class SeasonTeam extends Model
{
    /** Rozmiar Pucharu Polski: tylu zespołów z początku listy bierze w nim udział. */
    public const CUP_SIZE = 512;

    protected $fillable = ['season_id', 'position', 'user_id', 'bot_id', 'previous_id'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /* ------------------------------------------------------------------
     | Atrybuty wyliczane
     * ----------------------------------------------------------------*/

    /** Zespół bez właściciela to bot. */
    protected function isBot(): Attribute
    {
        return Attribute::get(fn () => $this->user_id === null);
    }

    /** Nazwa do wyświetlenia: nazwa zespołu gracza albo nazwa bota z tabeli bots. */
    protected function name(): Attribute
    {
        return Attribute::get(function () {
            if ($this->user) {
                return $this->user->team_name ?: $this->user->name;
            }

            return $this->bot?->name ?? __('Bot');
        });
    }

    /** Liga wynika wyłącznie z pozycji na liście (bez cache, bo pozycja się zmienia). */
    protected function league(): Attribute
    {
        return Attribute::get(fn () => League::forPosition($this->position))->withoutObjectCaching();
    }

    /* ------------------------------------------------------------------
     | Zapytania pomocnicze
     * ----------------------------------------------------------------*/

    /** SeasonTeam::inLeague(League::Ekstraklasa)->get() */
    public function scopeInLeague(Builder $query, League $league): Builder
    {
        $query->where('position', '>=', $league->firstPosition());

        if ($league->lastPosition() !== null) {
            $query->where('position', '<=', $league->lastPosition());
        }

        return $query;
    }
}
