<?php

use App\Enums\PlayerPosition;
use App\Support\Designation;
use App\Support\MatchReport;

/**
 * @return array{id: int, position: PlayerPosition, started: bool, on: int|null, off: int, goals: int, assists: int, own_goals: int, yellow: int, red: bool}
 */
function reportAppearance(int $id, PlayerPosition $position, bool $started = true, ?int $on = 0, int $off = MatchReport::END_REGULAR, int $goals = 0, int $assists = 0): array
{
    return [
        'id' => $id, 'position' => $position, 'started' => $started, 'on' => $on, 'off' => $off,
        'goals' => $goals, 'assists' => $assists, 'own_goals' => 0, 'yellow' => 0, 'red' => false,
    ];
}

/** @return array<string, float> */
function reportSettings(): array
{
    return array_map('floatval', Designation::DEFAULTS);
}

it('counts a full match: two halves, 130 hours of rest, experience with a goal', function () {
    $r = MatchReport::compute([reportAppearance(1, PlayerPosition::Forward, goals: 1)], 0, false, reportSettings())[1];

    expect($r['full_halves'])->toBe(2)
        ->and($r['partial_halves'])->toBe(0)
        ->and($r['play_time'])->toBe(2.0)
        ->and($r['rest_hours'])->toBe(130)
        ->and($r['clean_sheet'])->toBeNull()
        ->and($r['experience'])->toBe(4.0); // 1 skład + 2 czas + 1 gol
});

it('gives the time points and rest hours from the buttons', function (int $off, float $time, int $hours) {
    $r = MatchReport::compute([reportAppearance(1, PlayerPosition::Midfielder, off: $off)], 0, true, reportSettings())[1];

    expect($r['play_time'])->toBe($time)->and($r['rest_hours'])->toBe($hours);
})->with([
    '<45' => [1, 0.5, 40],
    '45' => [2, 1.0, 70],
    '<90' => [3, 1.5, 100],
    '90' => [4, 2.0, 130],
    '<105' => [5, 2.5, 160],
    '105' => [6, 3.0, 190],
    '<120' => [7, 3.5, 220],
    '120' => [8, 4.0, 250],
]);

it('counts a substitute who came on in the second half and played the whole extra time', function () {
    $r = MatchReport::compute([reportAppearance(1, PlayerPosition::Forward, started: false, on: 3, off: 8)], 0, true, reportSettings())[1];

    expect($r['full_halves'])->toBe(2)
        ->and($r['partial_halves'])->toBe(1)
        ->and($r['play_time'])->toBe(2.5)
        ->and($r['experience'])->toBe(3.0); // 0,5 ławka + 2,5 czas
});

it('counts a substitute who came on and off in the same half', function () {
    $r = MatchReport::compute([reportAppearance(1, PlayerPosition::Defender, started: false, on: 3, off: 3)], 0, false, reportSettings())[1];

    expect($r['partial_halves'])->toBe(1)
        ->and($r['play_time'])->toBe(0.5)
        ->and($r['rest_hours'])->toBe(40)
        ->and($r['clean_sheet'])->toBeTrue()
        ->and($r['experience'])->toBe(2.0); // 0,5 ławka + 0,5 czas + 1 czyste konto
});

it('gives no clean sheet when Lech conceded', function () {
    $r = MatchReport::compute([reportAppearance(1, PlayerPosition::Goalkeeper)], 1, false, reportSettings())[1];

    expect($r['clean_sheet'])->toBeFalse()
        ->and($r['experience'])->toBe(3.0);
});

it('gives a bench player who did not come on only the bench points', function () {
    $r = MatchReport::compute([reportAppearance(1, PlayerPosition::Defender, started: false, on: null)], 0, false, reportSettings())[1];

    expect($r['played'])->toBeFalse()
        ->and($r['play_time'])->toBe(0.0)
        ->and($r['rest_hours'])->toBe(0)
        ->and($r['clean_sheet'])->toBeNull()
        ->and($r['experience'])->toBe(0.5);
});

it('normalizes the exit before the entry', function () {
    expect(MatchReport::normalize(false, 3, 2, false))->toBe([3, 3])
        ->and(MatchReport::normalize(false, 2, 2, false))->toBe([2, 3])
        ->and(MatchReport::normalize(true, 5, 8, false))->toBe([0, 4]);
});

it('takes the rest hours from the settings', function () {
    $settings = ['rest_base_hours' => 0.0, 'rest_hours_per_half' => 24.0] + reportSettings();
    $r = MatchReport::compute([reportAppearance(1, PlayerPosition::Forward, off: 3)], 0, false, $settings)[1];

    expect($r['rest_hours'])->toBe(36);
});
