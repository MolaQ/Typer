<?php

namespace App\Enums;

/**
 * Struktura lig (regulamin, punkt 1). Wartość = poziom ligi (1 = najwyżej).
 * Poziomy 1-10 mają po 10 zespołów, poziom 11 (podwórkowa) resztę.
 * Nazwy lig to polskie nazwy własne, więc nie przechodzą przez tłumaczenia.
 */
enum League: int
{
    case Ekstraklasa = 1;
    case ILiga = 2;
    case IILiga = 3;
    case IIILiga = 4;
    case IVLiga = 5;
    case VLiga = 6;
    case Okregowa = 7;
    case AKlasa = 8;
    case BKlasa = 9;
    case CKlasa = 10;
    case Podworkowa = 11;

    /** Liczba zespołów w lidze poziomów 1-10. */
    public const SIZE = 10;

    /** Liczba zespołów w dziesięciu pierwszych ligach razem. */
    public const TOP_TEAMS = 100;

    public function label(): string
    {
        return match ($this) {
            self::Ekstraklasa => 'Ekstraklasa',
            self::ILiga => 'I liga',
            self::IILiga => 'II liga',
            self::IIILiga => 'III liga',
            self::IVLiga => 'IV liga',
            self::VLiga => 'V liga',
            self::Okregowa => 'Liga Okręgowa',
            self::AKlasa => 'A klasa',
            self::BKlasa => 'B klasa',
            self::CKlasa => 'C klasa',
            self::Podworkowa => 'Liga podwórkowa',
        };
    }

    /** Ligi 1-10 mają stałą liczbę zespołów, podwórkowa nie. */
    public function isTop(): bool
    {
        return $this !== self::Podworkowa;
    }

    /** Pierwsza pozycja listy należąca do ligi (Ekstraklasa 1, I liga 11, ...). */
    public function firstPosition(): int
    {
        return ($this->value - 1) * self::SIZE + 1;
    }

    /** Ostatnia pozycja ligi albo null dla podwórkowej (bez górnej granicy). */
    public function lastPosition(): ?int
    {
        return $this->isTop() ? $this->value * self::SIZE : null;
    }

    /** Liga, do której należy dana pozycja na liście. */
    public static function forPosition(int $position): self
    {
        $tier = intdiv(max($position, 1) - 1, self::SIZE) + 1;

        return self::from(min($tier, self::Podworkowa->value));
    }
}