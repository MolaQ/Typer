<?php

namespace App\Enums;

/**
 * Pozycja zawodnika z kadry Lecha. Szablon pytania może dotyczyć tylko wybranych pozycji (np. czyste konto: bramkarz),
 * a w typie zawodnika (etap 5) od pozycji zależy punktacja za gola i czyste konto.
 */
enum PlayerPosition: string
{
    case Goalkeeper = 'goalkeeper';
    case Defender = 'defender';
    case Midfielder = 'midfielder';
    case Forward = 'forward';

    public function label(): string
    {
        return match ($this) {
            self::Goalkeeper => __('Goalkeeper'),
            self::Defender => __('Defender'),
            self::Midfielder => __('Midfielder'),
            self::Forward => __('Forward'),
        };
    }

    /** Skrót do tabel: BR, OB, PO, NA. */
    public function short(): string
    {
        return match ($this) {
            self::Goalkeeper => __('GK'),
            self::Defender => __('DF'),
            self::Midfielder => __('MF'),
            self::Forward => __('FW'),
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
