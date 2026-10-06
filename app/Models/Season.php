<?php

namespace App\Models;

use App\Enums\SeasonStatus;
use App\Support\Roman;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Sezon rozgrywek. Do sezonu będą należeć liga i puchar (kolejne etapy).
 *
 * @property int $number
 * @property string|null $slogan
 * @property string|null $sponsor_name
 * @property string|null $sponsor_logo_path
 * @property SeasonStatus $status
 */
class Season extends Model
{
    protected $fillable = [
        'number',
        'slogan',
        'sponsor_name',
        'sponsor_logo_path',
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
     | Atrybuty wyliczane
     * ----------------------------------------------------------------*/

    /** $season->roman_number  ->  "IV" */
    protected function romanNumber(): Attribute
    {
        return Attribute::get(fn() => Roman::toRoman((int) $this->number));
    }

    /** $season->title  ->  "IV sezon" (tekst z pliku tłumaczeń) */
    protected function title(): Attribute
    {
        return Attribute::get(fn() => __(':roman season', ['roman' => $this->roman_number]));
    }

    /**
     * $season->sponsor_logo_url  ->  adres obrazka albo null.
     * Wymaga: php artisan storage:link oraz poprawnego APP_URL w .env.
     */
    protected function sponsorLogoUrl(): Attribute
    {
        return Attribute::get(fn() => $this->sponsor_logo_path
            ? Storage::disk('public')->url($this->sponsor_logo_path)
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