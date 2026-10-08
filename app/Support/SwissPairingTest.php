<?php

use App\Support\SwissPairing;

it('pairs neighbours in the first round', function () {
    $result = SwissPairing::pair(range(1, 6));

    expect($result['pairs'])->toBe([[1, 2], [3, 4], [5, 6]])
        ->and($result['bye'])->toBeNull();
});

it('always gives the bye to the lowest ranked team', function () {
    $result = SwissPairing::pair(range(1, 7), [], [7 => true]);

    expect($result['bye'])->toBe(7)
        ->and($result['pairs'])->toHaveCount(3);
});

it('avoids a rematch when possible', function () {
    $result = SwissPairing::pair([1, 2, 3, 4], [SwissPairing::key(1, 2) => true]);

    expect($result['pairs'])->toBe([[1, 3], [2, 4]]);
});
