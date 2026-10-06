<?php

namespace App\Enums;

/**
 * Stan kolejki: zaplanowana -> (przełożona) -> rozegrana.
 * "Rozegrana" ustawi się automatycznie przy wpisaniu wyniku (etap 11).
 */
enum MatchdayStatus: string
{
    case Planned = 'planned';
    case Postponed = 'postponed';
    case Played = 'played';

    public function label(): string
    {
        return match ($this) {
            self::Planned => __('Planned'),
            self::Postponed => __('Postponed'),
            self::Played => __('Played'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'zinc',
            self::Postponed => 'amber',
            self::Played => 'green',
        };
    }
}
