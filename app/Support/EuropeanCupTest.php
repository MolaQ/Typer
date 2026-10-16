<?php

use App\Support\EuropeanCup;

it('plays every pair of a group once and every team once per matchday', function () {
    $fixtures = EuropeanCup::groupFixtures();

    expect($fixtures)->toHaveCount(60);

    $pairs = [];
    foreach (range(1, EuropeanCup::GROUP_ROUNDS) as $round) {
        $seeds = collect($fixtures)->where('round', $round)->flatMap(fn ($f) => [$f['home'], $f['away']])->sort()->values()->all();
        expect($seeds)->toBe(range(1, EuropeanCup::TEAMS));
    }

    foreach ($fixtures as $f) {
        expect(EuropeanCup::groupOf($f['home']))->toBe(EuropeanCup::groupOf($f['away']))
            ->and($f['home'])->toBeLessThan($f['away']);
        $pairs[] = $f['home'].'-'.$f['away'];
    }

    expect(array_unique($pairs))->toHaveCount(60);
});

it('maps group places to seeds', function () {
    expect(EuropeanCup::seedOf('A', 1))->toBe(1)
        ->and(EuropeanCup::seedOf('D', 1))->toBe(4)
        ->and(EuropeanCup::seedOf('A', 2))->toBe(5)
        ->and(EuropeanCup::seedOf('D', 6))->toBe(24)
        ->and(EuropeanCup::groupOf(7))->toBe('C');
});

it('builds the knockout bracket so the best group winners meet only in the final', function () {
    $round16 = collect(EuropeanCup::knockoutFixtures())->where('round', 6)->map(fn ($f) => [$f['home'], $f['away']])->values()->all();

    expect($round16)->toBe([[1, 16], [2, 15], [3, 14], [4, 13], [5, 12], [6, 11], [7, 10], [8, 9]])
        ->and(collect(EuropeanCup::knockoutFixtures())->where('round', 8)->map(fn ($f) => [$f['home'], $f['away']])->values()->all())
        ->toBe([[1, 4], [2, 3]])
        ->and(collect(EuropeanCup::knockoutFixtures())->where('round', 9)->map(fn ($f) => [$f['home'], $f['away']])->values()->all())
        ->toBe([[1, 2]]);
});
