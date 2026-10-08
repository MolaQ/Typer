<?php

namespace App\Models;

use App\Enums\CompetitionType;
use App\Enums\QuestionSide;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Miejsce w zestawie pytań: kolejka + typ rozgrywek + strona + numer 1-5.
 *
 * @property int $matchday_id
 * @property CompetitionType $competition_type
 * @property QuestionSide $side
 * @property int $position
 * @property int $question_id
 * @property bool|null $correct_answer
 */
class MatchdayQuestion extends Model
{
    /** Liczba pytań na stronę w zestawie. */
    public const PER_SIDE = 5;

    protected $fillable = ['matchday_id', 'competition_type', 'side', 'position', 'question_id', 'correct_answer'];

    protected function casts(): array
    {
        return [
            'competition_type' => CompetitionType::class,
            'side' => QuestionSide::class,
            'position' => 'integer',
            'correct_answer' => 'boolean',
        ];
    }

    public function matchday(): BelongsTo
    {
        return $this->belongsTo(Matchday::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}