<?php

namespace App\Support;

/**
 * Losowanie pytań z puli (czysta funkcja, bez bazy, łatwa do przetestowania).
 */
final class QuestionPicker
{
    /**
     * Wybiera losowo $count różnych identyfikatorów z puli, pomijając $exclude.
     * Zwraca mniej elementów, jeśli pula jest za mała (decyzję podejmuje wywołujący).
     *
     * @param  array<int, int>  $pool  id pytań dostępnych do losowania
     * @param  array<int, int>  $exclude  id pytań już użytych w tej kolejce
     * @return array<int, int>
     */
    public static function pick(array $pool, array $exclude, int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        $available = array_values(array_diff(array_unique($pool), $exclude));
        shuffle($available);

        return array_slice($available, 0, $count);
    }
}