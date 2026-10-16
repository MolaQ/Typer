<?php

use App\Models\Fixture;
use App\Support\LegendsLeague;

it('pairs every team twice in a cycle', function () {
    expect(LegendsLeague::pairs([1, 2, 3, 4]))->toBe([[1, 2], [2, 3], [3, 4], [1, 4]]);
});

it('plays one match when two teams are left', function () {
    expect(LegendsLeague::pairs([5, 9]))->toBe([[5, 9]])
        ->and(LegendsLeague::pairs([5]))->toBe([]);
});

it('eliminates only teams with two losses in a matchday', function () {
    $fixture = fn (int $home, int $away, int $homeGoals, int $awayGoals) => new Fixture([
        'home_entry_id' => $home, 'away_entry_id' => $away, 'home_goals' => $homeGoals, 'away_goals' => $awayGoals,
    ]);

    // 1-2, 2-3, 3-4, 1-4: zespół 2 przegrywa dwa razy, 4 przegrywa raz i remisuje.
    $losers = LegendsLeague::losers([
        $fixture(1, 2, 3, 1),
        $fixture(2, 3, 0, 2),
        $fixture(3, 4, 1, 0),
        $fixture(1, 4, 2, 2),
    ]);

    expect($losers)->toBe([2]);
});
