<?php

namespace App\Models;

use App\Enums\QuestionSide;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Propozycja gracza: pytanie bonusowe (po akceptacji trafia do banku) albo nazwa bota (admin wybiera bota,
 * który ją dostaje). Admin akceptuje albo odrzuca.
 *
 * @property int $user_id
 * @property string $kind question / bot_name
 * @property string $text
 * @property QuestionSide|null $side
 * @property int|null $bot_id
 * @property string $status
 * @property string|null $reject_reason
 * @property int|null $question_id
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 */
class QuestionProposal extends Model
{
    public const QUESTION = 'question';

    public const BOT_NAME = 'bot_name';

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    /** Ile propozycji jeden gracz może mieć jednocześnie w kolejce. */
    public const MAX_PENDING = 5;

    protected $fillable = ['user_id', 'kind', 'bot_id', 'text', 'side', 'status', 'reject_reason', 'question_id', 'reviewed_by', 'reviewed_at'];

    protected $attributes = ['status' => self::PENDING, 'kind' => self::QUESTION];

    protected function casts(): array
    {
        return [
            'side' => QuestionSide::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function isBotName(): bool
    {
        return $this->kind === self::BOT_NAME;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }
}
