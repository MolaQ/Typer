<?php

namespace App\Enums;

/**
 * Cykl życia sezonu: szkic -> zatwierdzony -> aktywny -> zakończony.
 *  - Szkic: lista zespołów i kolejki można edytować.
 *  - Zatwierdzony: lista zamknięta, boty dodane, terminarz lig wygenerowany.
 *    Admin może jeszcze cofnąć zatwierdzenie (wraca szkic, terminarz znika).
 *  - Aktywny: sezon trwa (tylko jeden naraz), zatwierdzenia nie da się cofnąć.
 * Wartości zapisują się w bazie jako tekst (kolumna seasons.status), więc nowy
 * stan nie wymaga migracji.
 */
enum SeasonStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Active = 'active';
    case Finished = 'finished';

    /** Etykieta tłumaczona (klucze w lang/pl.json). */
    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            // Osobny klucz, bo "Approved" ma w tłumaczeniach rodzaj żeński (prośba zatwierdzona).
            self::Approved => __('Approved season'),
            self::Active => __('Active'),
            self::Finished => __('Finished'),
        };
    }

    /** Kolor plakietki flux:badge. */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Approved => 'amber',
            self::Active => 'green',
            self::Finished => 'blue',
        };
    }

    /** Czy lista zespołów i terminarz są już zamknięte? */
    public function isLocked(): bool
    {
        return $this !== self::Draft;
    }
}
