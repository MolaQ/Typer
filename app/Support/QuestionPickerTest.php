<?php

use App\Support\QuestionPicker;

it('picks the requested number of distinct ids', function () {
    $r = QuestionPicker::pick(range(1, 20), [], 5);
    expect($r)->toHaveCount(5)->and(array_unique($r))->toHaveCount(5);
});

it('skips excluded ids', function () {
    $r = QuestionPicker::pick(range(1, 10), [1, 2, 3, 4, 5], 5);
    expect($r)->toHaveCount(5)->and(array_intersect($r, [1, 2, 3, 4, 5]))->toBe([]);
});

it('returns fewer ids when the pool is too small', function () {
    expect(QuestionPicker::pick([1, 2], [], 5))->toHaveCount(2);
    expect(QuestionPicker::pick([1, 2], [1, 2], 5))->toBe([]);
    expect(QuestionPicker::pick([1, 2], [], 0))->toBe([]);
});