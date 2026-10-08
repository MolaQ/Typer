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

it('swaps bots for humans across a boundary', function () {
    // Ekstraklasa: 5 ludzi i 5 botów (miejsca 6-10), I liga: 7 ludzi na górze.
    $result = Promotion::apply([1 => league(1, 10, 5), 2 => league(11, 10, 7)]);

    // Bot z miejsca 6 spada dodatkowo, a człowiek z miejsca 5 I ligi awansuje dodatkowo.
    expect($result[1])->toBe([1, 2, 3, 4, 5, 11, 12, 13, 14, 15])
        ->and($result[2])->toBe([6, 7, 8, 9, 10, 16, 17, 18, 19, 20]);
});

it('keeps every league at the same size', function () {
    $result = Promotion::apply([1 => league(1, 10, 2), 2 => league(11, 10, 9), 3 => league(21, 25, 20)]);

    expect($result[1])->toHaveCount(10)
        ->and($result[2])->toHaveCount(10)
        ->and($result[3])->toHaveCount(25);
});
