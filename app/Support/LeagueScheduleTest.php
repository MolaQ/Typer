<?php

use App\Support\LeagueSchedule;

it('has 9 rounds with 5 matches each', function () {
    expect(LeagueSchedule::ROUNDS)->toHaveCount(9);

    foreach (LeagueSchedule::ROUNDS as $pairs) {
        expect($pairs)->toHaveCount(5);
    }
});

it('lets every team play exactly once in every round', function () {
    foreach (LeagueSchedule::ROUNDS as $pairs) {
        $seeds = collect($pairs)->flatten()->sort()->values()->all();

        expect($seeds)->toBe(range(1, LeagueSchedule::TEAMS));
    }
});

it('covers all 45 pairs exactly once', function () {
    $pairs = collect(LeagueSchedule::fixtures())
        ->map(fn ($f) => min($f['home'], $f['away']).'-'.max($f['home'], $f['away']));

    expect($pairs)->toHaveCount(45)
        ->and($pairs->unique())->toHaveCount(45);
});

it('makes the higher seed the home team', function () {
    foreach (LeagueSchedule::fixtures() as $fixture) {
        expect($fixture['home'])->toBeLessThan($fixture['away']);
    }
});
