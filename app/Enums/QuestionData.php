<?php

namespace App\Enums;

/**
 * Jakich danych wymaga rozstrzygnięcie pytania:
 *  - podstawowe: wynik, strzelcy, minuty goli, kartki, zmiany (protokół każdego meczu, także sparingu),
 *  - statystyki: posiadanie, strzały, rzuty rożne, faule, spalone (ekstraklasa.org, uefa.com; liga, PP i Europa).
 */
enum QuestionData: string
{
    case Basic = 'basic';
    case Stats = 'stats';

    public function label(): string
    {
        return match ($this) {
            self::Basic => __('Basic data'),
            self::Stats => __('Match statistics'),
        };
    }
}
