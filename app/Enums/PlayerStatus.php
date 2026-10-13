<?php

namespace App\Enums;

/**
 * Status zawodnika z kadry Lecha (bank pytań 2.0). Pytania z szablonów tworzą się i włączają same tylko dla grających
 * regularnie; dla pozostałych admin dodaje je ręcznie. Zawodnik wyłączony z losowania: żadne jego pytanie się nie losuje.
 */
enum PlayerStatus: string
{
    case Regular = 'regular';
    case Rotation = 'rotation';
    case Reserve = 'reserve';
    case Excluded = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::Regular => __('Plays regularly'),
            self::Rotation => __('Rotation player'),
            self::Reserve => __('Reserve'),
            self::Excluded => __('Excluded from the draw'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Regular => 'green',
            self::Rotation => 'sky',
            self::Reserve => 'zinc',
            self::Excluded => 'red',
        };
    }
}
