<?php

namespace App\Models;

use App\Enums\CompetitionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dorobek zespołu w kolejce dla jednego zestawu pytań (wynik ofensywny i defensywa).
 * Wynik zespołu w meczu = max(0, offense − defense_bonus rywala).
 *
 * @property int $matchday_id
 * @property int $season_team_id
 * @property CompetitionType $question_set
 * @property bool $has_tip
 * @property int|null $tip_lech
 * @property int|null $tip_opponent
 * @property \Illuminate\Support\Carbon|null $tipped_at
 * @property int $tip_points
 * @property bool $outcome_hit
 * @property bool $diff_hit
 * @property bool $exact_hit
 * @property int $offense_bonus
 * @property int $defense_bonus
 * @property bool $offense_zeroed
 * @property bool $defense_zeroed
 * @property int $offense
 */
class TeamScore extends Model
{
    protected $fillable = [
        'matchday_id', 'season_team_id', 'question_set',
        'has_tip', 'tip_lech', 'tip_opponent', 'tipped_at',
        'tip_points', 'outcome_hit', 'diff_hit', 'exact_hit',
        'offense_bonus', 'defense_bonus', 'offense_zeroed', 'defense_zeroed', 'offense',
    ];

    protected function casts(): array
    {
        return [
            'question_set' => CompetitionType::class,
            'has_tip' => 'boolean',
            'tip_lech' => 'integer',
            'tip_opponent' => 'integer',
            'tipped_at' => 'datetime:Y-m-d H:i:s.u',
            'tip_points' => 'integer',
            'outcome_hit' => 'boolean',
            'diff_hit' => 'boolean',
            'exact_hit' => 'boolean',
            'offense_bonus' => 'integer',
            'defense_bonus' => 'integer',
            'offense_zeroed' => 'boolean',
            'defense_zeroed' => 'boolean',
            'offense' => 'integer',
        ];
    }

    public function matchday(): BelongsTo
    {
        return $this->belongsTo(Matchday::class);
    }

    public function seasonTeam(): BelongsTo
    {
        return $this->belongsTo(SeasonTeam::class);
    }

    /** Bonusy z niewyzerowanych zestawów (kryterium tabeli). */
    public function bonus(): int
    {
        return $this->offense_bonus + $this->defense_bonus;
    }
}
