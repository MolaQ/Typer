<?php

namespace App\Models;

use App\Enums\MatchCompetition;
use App\Enums\MatchdayStatus;
use Illuminate\Database\Eloquent\Model;
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
 */
class LechMatch extends Model
{
    protected $fillable = ['opponent', 'is_home', 'competition', 'kickoff_at', 'postponed', 'note'];

    protected function casts(): array
    {
        return [
            'is_home' => 'boolean',
            'kickoff_at' => 'datetime',
            'postponed' => 'boolean',
        ];
    }

    /** Kolejka, do której mecz jest przypisany (najwyżej jedna). */
    public function matchday(): HasOne
    {
        return $this->hasOne(Matchday::class);
    }

    /** "Lech Poznań – Legia" albo "Legia – Lech Poznań". */
    public function fixture(): string
    {
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
