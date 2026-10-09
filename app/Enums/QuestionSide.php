<?php

namespace App\Enums;

/**
 * Strona pytania bonusowego (regulamin, punkt 4).
 *  - Ofensywne: o ataku Lecha, poprawne odpowiedzi dają punkty własnemu zespołowi.
 *  - Defensywne: o obronie Lecha i grze rywala, poprawne odpowiedzi pomniejszają wynik rywala.
 */
enum QuestionSide: string
{
    case Offensive = 'offensive';
    case Defensive = 'defensive';

    public function label(): string
    {
        return match ($this) {
            self::Offensive => __('Offensive'),
            self::Defensive => __('Defensive'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Offensive => 'green',
            self::Defensive => 'blue',
        };
    }
}
