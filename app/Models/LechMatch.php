<?php

namespace App\Models;

use App\Enums\MatchCompetition;
use App\Enums\MatchdayStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Mecz Lecha w kalendarzu (panel „Kalendarz Lecha”). Kolejka sezonu wybiera mecz z kalendarza
 * i kopiuje jego dane (Matchday::assignMatch).
 *
 * @property int $id
 * @property string $opponent
 * @property bool $is_home
 * @property string $competition
 * @property Carbon|null $kickoff_at
 * @property bool $postponed
 * @property string|null $note
 * @property int|null $lech_goals wynik po 90 minutach (raport meczu)
 * @property int|null $opponent_goals
 * @property bool $extra_time
 * @property int $et_lech_goals gole w dogrywce
 * @property int $et_opponent_goals
 * @property bool $penalties seria rzutów karnych (informacyjnie)
 * @property int|null $pen_lech_goals
 * @property int|null $pen_opponent_goals
 */
class LechMatch extends Model
{
    protected $fillable = [
        'opponent', 'is_home', 'competition', 'kickoff_at', 'postponed', 'note',
        'lech_goals', 'opponent_goals', 'extra_time', 'et_lech_goals', 'et_opponent_goals', 'penalties', 'pen_lech_goals', 'pen_opponent_goals',
    ];

    protected function casts(): array
    {
        return [
            'is_home' => 'boolean',
            'kickoff_at' => 'datetime',
            'postponed' => 'boolean',
            'lech_goals' => 'integer',
            'opponent_goals' => 'integer',
            'extra_time' => 'boolean',
            'et_lech_goals' => 'integer',
            'et_opponent_goals' => 'integer',
            'penalties' => 'boolean',
            'pen_lech_goals' => 'integer',
            'pen_opponent_goals' => 'integer',
        ];
    }

    /**
     * Kolejka, do której mecz jest przypisany (najwyżej jedna).
     *
     * @return HasOne<Matchday, $this>
     */
    public function matchday(): HasOne
    {
        return $this->hasOne(Matchday::class);
    }

    /**
     * Raport meczu: występy zawodników Lecha.
     *
     * @return HasMany<MatchAppearance, $this>
     */
    public function appearances(): HasMany
    {
        return $this->hasMany(MatchAppearance::class);
    }

    /**
     * Protokół meczu (zawodnicy Lecha) w kolejności wpisu.
     *
     * @return HasMany<MatchEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(MatchEvent::class)->orderBy('id');
    }

    /** Gole stracone przez Lecha w całym meczu (z dogrywką): do czystego konta. */
    public function conceded(): int
    {
        return (int) $this->opponent_goals + ($this->extra_time ? $this->et_opponent_goals : 0);
    }

    /**
     * „Mecz bez nazwy”: miejsce w kalendarzu utworzone razem z kolejkami sezonu (Season::createPlaceholderMatches),
     * rywala i termin admin uzupełnia później. Poznajemy go po pustym rywalu.
     */
    public function isPlaceholder(): bool
    {
        return blank($this->opponent);
    }

    /**
     * @param  Builder<LechMatch>  $query
     */
    public function scopePlaceholders(Builder $query): void
    {
        $query->where('opponent', '');
    }

    /** "Lech Poznań – Legia" albo "Legia – Lech Poznań", a dla meczu bez rywala „Mecz bez nazwy”. */
    public function fixture(): string
    {
        if ($this->isPlaceholder()) {
            return __('Unnamed match');
        }

        return $this->is_home ? 'Lech Poznań – '.$this->opponent : $this->opponent.' – Lech Poznań';
    }

    /**
     * Po zmianie meczu w kalendarzu: kolejka z tym meczem (jeszcze nierozegrana) dostaje nowe dane.
     * Przełożony mecz albo mecz bez terminu ustawia kolejkę jako przełożoną, typy zostają do nowego wyboru meczu.
     * Gdy nowy termin łamie kolejność kolejek, kolejka też czeka jako przełożona (zwracamy opis problemu).
     */
    public function syncMatchday(): ?string
    {
        $matchday = $this->matchday()->first();

        if ($matchday === null || $matchday->status === MatchdayStatus::Played) {
            return null;
        }

        $error = ! $this->postponed && $this->kickoff_at !== null ? $matchday->kickoffOrderError($this->kickoff_at) : null;
        $matchday->assignMatch($this);

        if ($error !== null) {
            $matchday->update(['status' => MatchdayStatus::Postponed]);

            return $error;
        }

        return null;
    }

    public function competitionLabel(): string
    {
        return MatchCompetition::tryFrom($this->competition)?->label() ?? $this->competition;
    }
}
