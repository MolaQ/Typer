<?php

namespace App\Support;

/**
 * Kolory kafelków z wartościami (jednakowe w całym serwisie, komponent x-tone-tile):
 * blue = jasnoniebieski (najlepiej), green, yellow, red (najgorzej), zinc = brak danych.
 * Klasy CSS są w komponencie, bo Tailwind skanuje tylko widoki.
 */
final class Tone
{
    /** Punkty za typ (0-3): 3 niebieski, 2 zielony, 1 żółty, 0 czerwony. */
    public static function tipPoints(?int $points): string
    {
        return match (true) {
            $points === null => 'zinc',
            $points >= 3 => 'blue',
            $points === 2 => 'green',
            $points === 1 => 'yellow',
            default => 'red',
        };
    }

    /** Bonus ofensywny albo defensywny (0-5): 5-4 niebieski, 3-2 zielony, 1 żółty, 0 czerwony. */
    public static function bonus(?int $points): string
    {
        return self::scale($points, 4, 2);
    }

    /** Atak, czyli typ + bonus ofensywny (0-8): 4-8 niebieski, 2-3 zielony, 1 żółty, 0 czerwony. */
    public static function attack(?int $points): string
    {
        return self::scale($points, 4, 2);
    }

    /** Obrona rywala (odejmowana od ataku): 5-3 czerwony, 2-1 żółty, 0 niebieski. */
    public static function rivalDefense(?int $points): string
    {
        return match (true) {
            $points === null => 'zinc',
            $points >= 3 => 'red',
            $points >= 1 => 'yellow',
            default => 'blue',
        };
    }

    /** Bramki: 0 czerwony, 1 żółty, 2-3 zielony, 4 i więcej niebieski. */
    public static function goals(?int $goals): string
    {
        return self::scale($goals, 4, 2);
    }

    private static function scale(?int $value, int $blue, int $green): string
    {
        return match (true) {
            $value === null => 'zinc',
            $value >= $blue => 'blue',
            $value >= $green => 'green',
            $value === 1 => 'yellow',
            default => 'red',
        };
    }
}
