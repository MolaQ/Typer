<?php

namespace App\Support;

/**
 * Zamiana liczby na zapis rzymski (4 -> IV, 2026 -> MMXXVI).
 * Numer sezonu trzymamy w bazie jako zwykłą liczbę (łatwe sortowanie i unikalność),
 * a rzymski zapis powstaje dopiero przy wyświetlaniu.
 */
class Roman
{
    /** Zakres, w którym zapis rzymski jest poprawny. */
    public const MIN = 1;

    public const MAX = 3999;

    public static function toRoman(int $number): string
    {
        // Poza zakresem zwracamy zwykłą liczbę, żeby widok nigdy się nie wysypał.
        if ($number < self::MIN || $number > self::MAX) {
            return (string) $number;
        }

        $map = [
            'M' => 1000,
            'CM' => 900,
            'D' => 500,
            'CD' => 400,
            'C' => 100,
            'XC' => 90,
            'L' => 50,
            'XL' => 40,
            'X' => 10,
            'IX' => 9,
            'V' => 5,
            'IV' => 4,
            'I' => 1,
        ];

        $result = '';

        // Od największej wartości w dół: odejmujemy, dopóki się mieści.
        foreach ($map as $symbol => $value) {
            while ($number >= $value) {
                $result .= $symbol;
                $number -= $value;
            }
        }

        return $result;
    }
}
