<?php

namespace App\Enums;

/**
 * Cykl życia sezonu: szkic -> aktywny -> zakończony.
 * Wartości zapisują się w bazie jako tekst (kolumna seasons.status).
 */
enum SeasonStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Finished = 'finished';

    /** Etykieta tłumaczona (klucze w lang/pl.json). */
    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Active => __('Active'),
            self::Finished => __('Finished'),
        };
    }

    /** Kolor plakietki flux:badge. */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Active => 'green',
            self::Finished => 'blue',
        };
    }
}