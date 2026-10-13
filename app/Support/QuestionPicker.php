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
     * Z pytań oznaczonych w $limited (np. pytania o zawodników) wybiera najwyżej $limit.
     *
     * @param  array<int, int>  $pool  id pytań dostępnych do losowania
     * @param  array<int, int>  $exclude  id pytań już użytych w tej kolejce
     * @param  array<int, int>  $limited  id pytań objętych limitem
     * @return array<int, int>
     */
    public static function pick(array $pool, array $exclude, int $count, array $limited = [], int $limit = PHP_INT_MAX): array
    {
        if ($count <= 0) {
            return [];
        }

        $available = array_values(array_diff(array_unique($pool), $exclude));
        shuffle($available);

        $limitedSet = array_flip($limited);
        $picked = [];

        foreach ($available as $id) {
            if (count($picked) >= $count) {
                break;
            }

            if (isset($limitedSet[$id])) {
                if ($limit <= 0) {
                    continue;
                }
                $limit--;
            }

            $picked[] = $id;
        }

        return $picked;
    }
}
