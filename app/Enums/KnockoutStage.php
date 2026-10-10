<?php

namespace App\Enums;

/**
 * Etapy rozgrywek pucharowych: 9 rund Pucharu Polski i 9 kolejek Ligi Legend
 * (512 → 256 → … → finał 2 zespołów). Wartość to numer rundy (kolejki).
 */
enum KnockoutStage: int
{
    case FirstQualifying = 1;
    case SecondQualifying = 2;
    case RoundOf128 = 3;
    case RoundOf64 = 4;
    case RoundOf32 = 5;
    case RoundOf16 = 6;
    case QuarterFinal = 7;
    case SemiFinal = 8;
    case Final = 9;

    public function label(): string
    {
        return match ($this) {
            self::FirstQualifying => __('First qualifying round'),
            self::SecondQualifying => __('Second qualifying round'),
            self::RoundOf128 => '1/64',
            self::RoundOf64 => '1/32',
            self::RoundOf32 => '1/16',
            self::RoundOf16 => '1/8',
            self::QuarterFinal => __('Quarter-final'),
            self::SemiFinal => __('Semi-final'),
            self::Final => __('Final'),
        };
    }

    /** Nazwa etapu dla numeru rundy; poza zakresem zwykłe „Kolejka N”. */
    public static function labelFor(int $round): string
    {
        return self::tryFrom($round)?->label() ?? __('Matchday :number', ['number' => $round]);
    }
}
