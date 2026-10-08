<?php

namespace App\Support;

/**
 * Czyste reguły walidacji typu (bez bazy, łatwe do przetestowania).
 */
final class TipRules
{
    /** Największa liczba goli, jaką można wytypować dla jednej drużyny. */
    public const MAX_GOALS = 20;

    /** Zamienia wejście z formularza na liczbę goli 0..MAX_GOALS albo null, gdy jest niepoprawne. */
    public static function goals(mixed $value): ?int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/^\s*\d{1,3}\s*$/', $value)) {
            $number = (int) trim($value);
        } else {
            return null;
        }

        return $number >= 0 && $number <= self::MAX_GOALS ? $number : null;
    }

    /** Odpowiedź tak/nie: "1"/1/true => true, "0"/0/false => false, wszystko inne (np. "") => null. */
    public static function answer(mixed $value): ?bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1' => true,
            $value === false, $value === 0, $value === '0' => false,
            default => null,
        };
    }
}