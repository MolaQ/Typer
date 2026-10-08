<?php

use App\Support\CupBracket;

it('has 9 rounds and 511 matches', function () {
    expect(CupBracket::fixtures())->toHaveCount(511);

    $perRound = collect(CupBracket::fixtures())->groupBy('round')->map->count()->values()->all();
    expect($perRound)->toBe([256, 128, 64, 32, 16, 8, 4, 2, 1]);
});

it('pairs the best seat with the worst in every round', function () {
    foreach (range(1, CupBracket::ROUNDS) as $round) {
        $seats = CupBracket::seatsInRound($round);

        foreach (CupBracket::pairs($round) as [$home, $away]) {
            expect($home + $away)->toBe($seats + 1)
                ->and($home)->toBeLessThan($away);
        }
    }
});

it('uses every seat exactly once in a round', function () {
    foreach (range(1, CupBracket::ROUNDS) as $round) {
        $seats = collect(CupBracket::pairs($round))->flatten()->sort()->values()->all();

        expect($seats)->toBe(range(1, CupBracket::seatsInRound($round)));
    }
});

it('ends with the final between seats 1 and 2', function () {
    expect(CupBracket::pairs(9))->toBe([[1, 2]]);
});
