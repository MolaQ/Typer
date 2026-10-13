<?php

namespace App\Enums;

/**
 * Status zawodnika z kadry Lecha. Wyłączony (kontuzja, odejście) nie będzie do wyboru przy desygnowaniu.
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
            self::Excluded => __('Unavailable'),
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
