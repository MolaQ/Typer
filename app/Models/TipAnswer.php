<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Odpowiedź gracza (tak/nie) na jedno miejsce w zestawie pytań kolejki.
 *
 * @property int $matchday_id
 * @property int $user_id
 * @property int $matchday_question_id
 * @property bool $answer
 */
class TipAnswer extends Model
{
    protected $fillable = ['matchday_id', 'user_id', 'matchday_question_id', 'answer'];

    protected function casts(): array
    {
        return ['answer' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matchdayQuestion(): BelongsTo
    {
        return $this->belongsTo(MatchdayQuestion::class);
    }
}
