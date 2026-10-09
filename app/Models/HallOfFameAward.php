<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Punkty (i ewentualnie trofeum) Hall of Fame zdobyte przez zespół w jednych rozgrywkach zakończonego sezonu.
 *
 * @property int $season_id
 * @property int|null $competition_id
 * @property int|null $season_team_id
 * @property int|null $user_id
 * @property int|null $bot_id
 * @property string $kind matches, champion, second, third, promotion, top_scorer, cup_rounds, cup_winner, title, legends_stages, golden_title
 * @property string|null $trophy klucz trofeum do gabloty (App\Support\HallOfFame::trophies())
 * @property float $points
 * @property array|null $meta
 */
class HallOfFameAward extends Model
{
    protected $fillable = ['season_id', 'competition_id', 'season_team_id', 'user_id', 'bot_id', 'kind', 'trophy', 'points', 'meta'];

    protected function casts(): array
    {
        return [
            'points' => 'float',
            'meta' => 'array',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
