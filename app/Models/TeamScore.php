<?php

namespace App\Models;

use App\Enums\CompetitionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dorobek zespołu w kolejce dla jednego typu rozgrywek (CompetitionType::scoreSet): wynik ofensywny i defensywa.
 * Wynik zespołu w meczu = max(0, offense − defense_bonus rywala).
 *
 * @property int $matchday_id
 * @property int $season_team_id
 * @property int|null $user_id kto grał miejscem w tej kolejce (App\Support\Ownership)
 * @property int|null $bot_id
 * @property CompetitionType $score_set
 * @property bool $has_tip
 * @property int|null $tip_lech
 * @property int|null $tip_opponent
 * @property Carbon|null $tipped_at
 * @property int|null $tip_order numer zapisu typu (Tip::save_order), null = bot albo brak typu
 * @property int $tip_points
 * @property bool $outcome_hit
 * @property bool $diff_hit
 * @property bool $exact_hit
 * @property int $offense_bonus
 * @property int $defense_bonus
 * @property int $offense
 * @property int $own_goals samobóje wystawionych zawodników (punkty ataku dla rywala)
 * @property float $experience doświadczenie wystawionych zawodników w klubie (remisy w pucharach)
 * @property int $yellow_cards
 * @property int $red_cards
 */
class TeamScore extends Model
{
    protected $fillable = [
        'user_id', 'bot_id',
        'matchday_id', 'season_team_id', 'score_set',
        'has_tip', 'tip_lech', 'tip_opponent', 'tipped_at', 'tip_order',
        'tip_points', 'outcome_hit', 'diff_hit', 'exact_hit',
        'offense_bonus', 'defense_bonus', 'offense',
        'own_goals', 'experience', 'yellow_cards', 'red_cards',
    ];

    protected function casts(): array
    {
        return [
            'score_set' => CompetitionType::class,
            'has_tip' => 'boolean',
            'tip_lech' => 'integer',
            'tip_opponent' => 'integer',
            'tipped_at' => 'datetime:Y-m-d H:i:s.u',
            'tip_order' => 'integer',
            'tip_points' => 'integer',
            'outcome_hit' => 'boolean',
            'diff_hit' => 'boolean',
            'exact_hit' => 'boolean',
            'offense_bonus' => 'integer',
            'defense_bonus' => 'integer',
            'experience' => 'float',
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
