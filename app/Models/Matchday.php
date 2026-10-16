<?php

namespace App\Models;

use App\Enums\MatchCompetition;
use App\Enums\MatchdayStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Kolejka sezonu z jednym rzeczywistym meczem Lecha.
 *
 * @property int $season_id
 * @property int|null $lech_match_id mecz z kalendarza Lecha (dane są kopiowane do kolejki)
 * @property int $number
 * @property string|null $opponent
 * @property bool $is_home
 * @property string|null $competition
 * @property Carbon|null $kickoff_at
 * @property MatchdayStatus $status
 */
class Matchday extends Model
{
    /** Liczba kolejek w sezonie (regulamin: 9). */
    public const PER_SEASON = 9;

    protected $fillable = [
        'season_id',
        'lech_match_id',
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

    /** @return BelongsTo<LechMatch, $this> */
    public function lechMatch(): BelongsTo
    {
        return $this->belongsTo(LechMatch::class);
    }

    /**
     * Przypisuje mecz z kalendarza: kolejka dostaje kopię danych i status „zaplanowana”.
     * Typy graczy zostają (np. po przełożeniu meczu i wyborze nowego), gracze mogą je zmienić do nowego gwizdka.
     * Przełożona jest tylko kolejka z meczem oznaczonym jako przełożony. Mecz bez terminu (np. „mecz bez nazwy”
     * utworzony z sezonem) zostawia kolejkę zaplanowaną, a typowanie i tak otwiera się dopiero z terminem.
     * Poprzedni „mecz bez nazwy” tej kolejki, który nie jest już nigdzie przypisany, jest usuwany z kalendarza.
     */
    public function assignMatch(LechMatch $match): void
    {
        $previous = $this->lech_match_id;

        $this->fill([
            'lech_match_id' => $match->id,
            'opponent' => $match->opponent,
            'is_home' => $match->is_home,
            'competition' => $match->competition,
            'kickoff_at' => $match->kickoff_at,
            'status' => $match->postponed ? MatchdayStatus::Postponed : MatchdayStatus::Planned,
        ])->save();

        if ($previous !== null && $previous !== $match->id) {
            LechMatch::placeholders()->whereKey($previous)->whereDoesntHave('matchday')->delete();
        }
    }

    /**
     * Czy termin mieści się między sąsiednimi zaplanowanymi kolejkami (kolejka 3 nie może być przed kolejką 2)?
     * Zwraca opis błędu albo null, gdy termin jest dobry.
     */
    public function kickoffOrderError(\DateTimeInterface $kickoff): ?string
    {
        $neighbours = self::where('season_id', $this->season_id)->where('status', MatchdayStatus::Planned->value)->whereNotNull('kickoff_at');

        $previous = (clone $neighbours)->where('number', '<', $this->number)->orderByDesc('number')->first();
        $next = (clone $neighbours)->where('number', '>', $this->number)->orderBy('number')->first();

        if ($previous && $previous->kickoff_at && $kickoff <= $previous->kickoff_at) {
            return __('The kickoff must be later than matchday :n (:date).', ['n' => $previous->number, 'date' => $previous->kickoff_at->format('d.m.Y H:i')]);
        }

        if ($next && $next->kickoff_at && $kickoff >= $next->kickoff_at) {
            return __('The kickoff must be earlier than matchday :n (:date).', ['n' => $next->number, 'date' => $next->kickoff_at->format('d.m.Y H:i')]);
        }

        return null;
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

    /**
     * Nazwa rozgrywek meczu do wyświetlenia: etykieta enuma MatchCompetition (np. „Liga Mistrzów”),
     * a dla starszych wpisów z dowolnym tekstem ten tekst bez zmian. Pusta wartość -> null.
     */
    public function competitionLabel(): ?string
    {
        if (blank($this->competition)) {
            return null;
        }

        return MatchCompetition::tryFrom($this->competition)?->label() ?? $this->competition;
    }

    /** $matchday->fixture -> "Lech Poznań – Legia" albo "Legia – Lech Poznań". */
    protected function fixture(): Attribute
    {
        return Attribute::get(function () {
            if (! filled($this->opponent)) {
                return '—';
            }

            return $this->is_home
                ? 'Lech Poznań – '.$this->opponent
                : $this->opponent.' – Lech Poznań';
        });
    }
}
