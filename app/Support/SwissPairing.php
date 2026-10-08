<?php

namespace App\Support;

/**
 * Parowanie systemem szwajcarskim dla Ligi podwórkowej (czysta funkcja, bez bazy).
 *
 * Wejście: lista identyfikatorów od najlepszego do najgorszego (w rundzie 1 według listy
 * przedsezonowej, w kolejnych według aktualnej klasyfikacji), pary już rozegrane i zespoły,
 * które miały już wolny los. Wyjście: pary (lepszy zespół pierwszy, czyli po lewej) oraz
 * zespół z wolnym losem, gdy liczba zespołów jest nieparzysta (gra z wirtualnym rywalem).
 *
 * Zasady:
 *  - pierwszy wolny zespół z góry listy gra z najbliższym niżej, z którym jeszcze nie grał,
 *  - wolny los dostaje najgorszy zespół, który jeszcze go nie miał,
 *  - gdy nie da się uniknąć powtórki, bierzemy najbliższego rywala (bez zawieszania losowania).
 */
final class SwissPairing
{
    /**
     * @param  array<int, int>  $ranked  id od najlepszego
     * @param  array<string, bool>  $played  klucze "mniejszeId-większeId"
     * @param  array<int, bool>  $hadBye  id => true
     * @return array{pairs: array<int, array{0: int, 1: int}>, bye: ?int}
     */
    public static function pair(array $ranked, array $played = [], array $hadBye = []): array
    {
        $ranked = array_values($ranked);
        $bye = null;

        if (count($ranked) % 2 === 1) {
            // Najgorszy zespół bez wcześniejszego wolnego losu (a gdy wszyscy mieli, po prostu najgorszy).
            for ($i = count($ranked) - 1; $i >= 0; $i--) {
                if (empty($hadBye[$ranked[$i]])) {
                    $bye = $ranked[$i];
                    array_splice($ranked, $i, 1);
                    break;
                }
            }

            if ($bye === null) {
                $bye = array_pop($ranked);
            }
        }

        $pairs = [];

        while ($ranked !== []) {
            $a = array_shift($ranked);
            $index = 0;

            foreach ($ranked as $i => $candidate) {
                if (! isset($played[self::key($a, $candidate)])) {
                    $index = $i;
                    break;
                }
            }

            $b = $ranked[$index];
            array_splice($ranked, $index, 1);
            $pairs[] = [$a, $b];
        }

        return ['pairs' => $pairs, 'bye' => $bye];
    }

    public static function key(int $a, int $b): string
    {
        return min($a, $b).'-'.max($a, $b);
    }
}
