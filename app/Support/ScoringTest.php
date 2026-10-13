<?php

use App\Support\Scoring;

it('scores the tip from 0 to 3', function () {
    expect(Scoring::tip(2, 1, 2, 1)['points'])->toBe(3)
        ->and(Scoring::tip(2, 1, 3, 2)['points'])->toBe(2)
        ->and(Scoring::tip(2, 1, 1, 0)['points'])->toBe(2)
        ->and(Scoring::tip(3, 1, 1, 0)['points'])->toBe(1)
        ->and(Scoring::tip(1, 1, 2, 2)['points'])->toBe(2)
        ->and(Scoring::tip(1, 1, 2, 1)['points'])->toBe(0)
        ->and(Scoring::tip(0, 2, 2, 0)['points'])->toBe(0);
});

it('never gives negative goals', function () {
    expect(Scoring::goals(2, 3))->toBe(0)
        ->and(Scoring::goals(5, 2))->toBe(3);
});

it('gives 3, 1 and 0 match points', function () {
    expect(Scoring::matchPoints(2, 1))->toBe(3)
        ->and(Scoring::matchPoints(1, 1))->toBe(1)
        ->and(Scoring::matchPoints(0, 1))->toBe(0);
});

it('lets the earlier tip win a cup draw', function () {
    expect(Scoring::homeWinsOnTime(1, 2))->toBeTrue()
        ->and(Scoring::homeWinsOnTime(5, 4))->toBeFalse()
        ->and(Scoring::homeWinsOnTime(7, null))->toBeTrue()
        ->and(Scoring::homeWinsOnTime(null, 7))->toBeFalse()
        ->and(Scoring::homeWinsOnTime(null, null))->toBeTrue();
});
