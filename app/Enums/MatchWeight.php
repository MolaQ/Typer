<?php

namespace App\Enums;

/**
 * Waga meczu Lecha (szacunek admina): łatwy, wyrównany, trudny.
 * Pytanie z banku ma listę wag, do których pasuje (np. „posiadanie > 60%” tylko w łatwych meczach).
 */
enum MatchWeight: string
{
    case Easy = 'easy';
    case Even = 'even';
    case Hard = 'hard';

    public function label(): string
    {
        return match ($this) {
            self::Easy => __('Easy'),
            self::Even => __('Even'),
            self::Hard => __('Hard'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Easy => 'green',
            self::Even => 'amber',
            self::Hard => 'red',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
