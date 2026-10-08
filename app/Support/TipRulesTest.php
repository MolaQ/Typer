<?php

use App\Support\TipRules;

it('accepts goals from 0 to the maximum', function () {
    expect(TipRules::goals('0'))->toBe(0)
        ->and(TipRules::goals(' 3 '))->toBe(3)
        ->and(TipRules::goals(7))->toBe(7)
        ->and(TipRules::goals((string) TipRules::MAX_GOALS))->toBe(TipRules::MAX_GOALS);
});

it('rejects invalid goals', function () {
    foreach (['', '-1', '21', '1.5', 'abc', null, 2.5, [], '1000'] as $bad) {
        expect(TipRules::goals($bad))->toBeNull();
    }
});

it('normalises yes/no answers', function () {
    expect(TipRules::answer('1'))->toBeTrue()
        ->and(TipRules::answer(true))->toBeTrue()
        ->and(TipRules::answer('0'))->toBeFalse()
        ->and(TipRules::answer(false))->toBeFalse()
        ->and(TipRules::answer(''))->toBeNull()
        ->and(TipRules::answer(null))->toBeNull()
        ->and(TipRules::answer('yes'))->toBeNull();
});