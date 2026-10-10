<?php

namespace App\Models;

use App\Enums\SeasonStatus;
use App\Support\Roman;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sezon rozgrywek. Do sezonu należą kolejki (mecze Lecha), a w kolejnych
 * etapach liga, puchar i pozostałe rozgrywki.
 *
 * @property int $number
 * @property string|null $slogan
 * @property string|null $sponsor_name
 * @property string|null $sponsor_logo_path
 * @property string|null $sponsor_url
 * @property SeasonStatus $status
 * @property-read string $title nazwa sezonu, np. „IV sezon” (akcesor title())
 * @property-read string $roman_number
 */
class Season extends Model
{
    protected $fillable = [
        'number',
        'slogan',
        'sponsor_name',
        'sponsor_logo_path',
        'sponsor_url',
        'status',
        'starts_on',
        'ends_on',
    ];

    /** Nowy sezon domyślnie jest szkicem (także zanim zapisze się do bazy). */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => SeasonStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /* ------------------------------------------------------------------
     | Relacje
     * ----------------------------------------------------------------*/

    /** $season->matchdays  ->  kolejki 1-9 posortowane po numerze. */
    public function matchdays(): HasMany
    {
        return $this->hasMany(Matchday::class)->orderBy('number');
    }

    /** $season->teams  ->  lista przedsezonowa posortowana po pozycji. */
    public function teams(): HasMany
    {
        return $this->hasMany(SeasonTeam::class)->orderBy('position');
    }

    /** $season->competitions  ->  rozgrywki sezonu (po zatwierdzeniu: 10 lig). */
    public function competitions(): HasMany
    {
        return $this->hasMany(Competition::class)->orderBy('tier')->orderBy('id');
    }

    /**
     * Tworzy brakujące puste kolejki 1-9 (istniejących nie rusza).
     * Wywoływane przy tworzeniu sezonu; admin uzupełnia rywala i termin w "Kolejkach".
     *
     * @return int ile kolejek utworzono
     */
    public function createMissingMatchdays(): int
    {
        $existing = $this->matchdays()->pluck('number')->all();
        $created = 0;

        for ($n = 1; $n <= Matchday::PER_SEASON; $n++) {
            if (! in_array($n, $existing, true)) {
                $this->matchdays()->create(['number' => $n]);
                $created++;
            }
        }

        return $created;
    }

    /* ------------------------------------------------------------------
     | Atrybuty wyliczane
     * ----------------------------------------------------------------*/

    /** $season->roman_number  ->  "IV" */
    protected function romanNumber(): Attribute
    {
        return Attribute::get(fn () => Roman::toRoman((int) $this->number));
    }

    /** $season->title  ->  "IV sezon" (tekst z pliku tłumaczeń) */
    protected function title(): Attribute
    {
        return Attribute::get(fn () => __(':roman season', ['roman' => $this->roman_number]));
    }

    /**
     * $season->sponsor_logo_url  ->  adres obrazka albo null.
     * Wymaga: php artisan storage:link oraz poprawnego APP_URL w .env.
     */
    protected function sponsorLogoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->sponsor_logo_path
            ? asset('storage/'.$this->sponsor_logo_path)
            : null);
    }

    /* ------------------------------------------------------------------
     | Zapytania pomocnicze
     * ----------------------------------------------------------------*/

    /** Season::active()->get() */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SeasonStatus::Active->value);
    }

    /** Bieżący (aktywny) sezon albo null. Użyjemy go na stronie głównej. */
    public static function current(): ?self
    {
        return static::active()->latest('number')->first();
    }
}
