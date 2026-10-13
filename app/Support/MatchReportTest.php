<?php

use App\Enums\PlayerPosition;
use App\Support\Designation;
use App\Support\MatchReport;

function reportAppearance(int $id, PlayerPosition $position, bool $started = true, ?int $on = null, ?int $off = null): array
{
    return ['id' => $id, 'position' => $position, 'started' => $started, 'on' => $on, 'off' => $off, 'yellow' => false, 'red' => false];
}

function reportGoal(string $side, ?int $minute, ?int $scorer = null, ?int $assist = null): array
{
    return ['side' => $side, 'minute' => $minute, 'scorer' => $scorer, 'assist' => $assist];
}

it('counts halves, lock and experience of a full match', function () {
    $r = MatchReport::compute([reportAppearance(1, PlayerPosition::Forward)], [reportGoal('lech', 30, 1)], false, Designation::DEFAULTS)[1];

    expect($r['full_halves'])->toBe(2)
        ->and($r['partial_halves'])->toBe(0)
        ->and($r['lock'])->toBe(2)
        ->and($r['goals'])->toBe(1)
        ->and($r['match_points'])->toBe(3)
        ->and($r['experience'])->toBe(8);
});

it('locks a full match with extra time for four matchdays', function () {
    $r = MatchReport::compute([reportAppearance(1, PlayerPosition::Midfielder)], [], true, Designation::DEFAULTS)[1];

    expect($r['full_halves'])->toBe(4)
        ->and($r['lock'])->toBe(4)
        ->and($r['experience'])->toBe(4 * 2 + 1 + 1);
});

it('splits a half-time substitution into whole halves', function () {
    $r = MatchReport::compute([
        reportAppearance(1, PlayerPosition::Forward, off: 46),
        reportAppearance(2, PlayerPosition::Forward, started: false, on: 46),
    ], [], false, Designation::DEFAULTS);

    expect($r[1]['full_halves'])->toBe(1)
        ->and($r[1]['partial_halves'])->toBe(0)
        ->and($r[2]['full_halves'])->toBe(1)
        ->and($r[2]['experience'])->toBe(2 + 1);
});

it('scores the player match from goals while on the pitch', function () {
    $r = MatchReport::compute([
        reportAppearance(1, PlayerPosition::Forward, off: 60),
        reportAppearance(2, PlayerPosition::Forward, started: false, on: 60),
    ], [reportGoal('lech', 20), reportGoal('opponent', 75)], false, Designation::DEFAULTS);

    expect($r[1]['match_points'])->toBe(3)
        ->and($r[2]['match_points'])->toBe(0)
        ->and($r[2]['partial_halves'])->toBe(1)
        ->and($r[2]['lock'])->toBe(0);
});

it('gives a clean sheet only to goalkeepers and defenders who played', function () {
    $players = [
        reportAppearance(1, PlayerPosition::Goalkeeper),
        reportAppearance(2, PlayerPosition::Defender, off: 60),
        reportAppearance(3, PlayerPosition::Defender, started: false, on: 60),
        reportAppearance(4, PlayerPosition::Forward),
    ];
    $goals = [reportGoal('opponent', 30)];

    $match = MatchReport::compute($players, $goals, false, Designation::DEFAULTS);
    $fromOn = MatchReport::compute($players, $goals, false, ['clean_sheet_mode' => Designation::CLEAN_FROM_ON] + Designation::DEFAULTS);

    expect($match[1]['clean_sheet'])->toBeFalse()
        ->and($match[3]['clean_sheet'])->toBeFalse()
        ->and($match[4]['clean_sheet'])->toBeNull()
        ->and($fromOn[3]['clean_sheet'])->toBeTrue()
        ->and($fromOn[2]['clean_sheet'])->toBeFalse();
});

it('counts own goals of Lech players', function () {
    $r = MatchReport::compute([reportAppearance(1, PlayerPosition::Defender)], [reportGoal('opponent', 10, 1)], false, Designation::DEFAULTS)[1];

    expect($r['own_goals'])->toBe(1)
        ->and($r['against'])->toBe(1)
        ->and($r['clean_sheet'])->toBeFalse();
});
