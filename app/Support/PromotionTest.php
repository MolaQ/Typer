<?php

use App\Support\Promotion;

/** Liga z id od $from, $humans pierwszych to ludzie. */
function league(int $from, int $size = 10, ?int $humans = null): array
{
    $teams = [];
    for ($i = 0; $i < $size; $i++) {
        $teams[] = ['id' => $from + $i, 'human' => $i < ($humans ?? $size)];
    }

    return $teams;
}

it('moves four teams between neighbouring leagues', function () {
    $result = Promotion::apply([1 => league(1), 2 => league(11), 3 => league(21)]);

    // Ekstraklasa: pozostali 1-6, potem beniaminkowie 11-14.
    expect($result[1])->toBe([1, 2, 3, 4, 5, 6, 11, 12, 13, 14])
        // I liga: spadkowicze 7-10, pozostali 15-16, beniaminkowie 21-24.
        ->and($result[2])->toBe([7, 8, 9, 10, 15, 16, 21, 22, 23, 24])
        // Najniższa: spadkowicze 17-20, pozostali 25-30.
        ->and($result[3])->toBe([17, 18, 19, 20, 25, 26, 27, 28, 29, 30]);
});

it('pushes bots below every human', function () {
    // Ekstraklasa: 5 ludzi i 5 botów (miejsca 6-10), I liga: 7 ludzi na górze.
    $result = Promotion::apply([1 => league(1, 10, 5), 2 => league(11, 10, 7)]);

    // Po 4 w górę i w dół, potem ludzie przed botami: Ekstraklasa cała ludzka, boty od najlepszego (6. miejsce Ekstraklasy).
    expect($result[1])->toBe([1, 2, 3, 4, 5, 11, 12, 13, 14, 15])
        ->and($result[2])->toBe([16, 17, 6, 7, 8, 9, 10, 18, 19, 20]);
});

it('moves bots to the end of the table before promotion', function () {
    // I liga: bot na 1. miejscu, ludzie za nim. Bot nie awansuje, awansują 4 najlepsi ludzie.
    $second = league(11);
    $second[0]['human'] = false;

    $result = Promotion::apply([1 => league(1), 2 => $second]);

    expect($result[1])->toBe([1, 2, 3, 4, 5, 6, 12, 13, 14, 15])
        ->and($result[2])->toBe([7, 8, 9, 10, 16, 17, 18, 19, 20, 11]);
});

it('lets a human jump several leagues over bots', function () {
    // Ekstraklasa i I liga same boty, najlepszy człowiek II ligi kończy w Ekstraklasie.
    $result = Promotion::apply([1 => league(1, 10, 0), 2 => league(11, 10, 0), 3 => league(21, 10, 1)]);

    expect($result[1][0])->toBe(21)
        // Najlepszy bot (mistrz Ekstraklasy) otwiera listę botów.
        ->and($result[1][1])->toBe(1);
});

it('keeps every league at the same size', function () {
    $result = Promotion::apply([1 => league(1, 10, 2), 2 => league(11, 10, 9), 3 => league(21, 25, 20)]);

    expect($result[1])->toHaveCount(10)
        ->and($result[2])->toHaveCount(10)
        ->and($result[3])->toHaveCount(25);
});
