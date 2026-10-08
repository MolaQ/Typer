<?php

namespace App\Models;

use App\Enums\QuestionSide;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Propozycja pytania bonusowego od gracza. Admin akceptuje ją (pytanie trafia do banku) albo odrzuca.
 *
 * @property int $user_id
 * @property string $text
 * @property QuestionSide $side
 * @property string $status
 * @property string|null $reject_reason
 * @property int|null $question_id
 * @property int|null $reviewed_by
 * @property \Carbon\CarbonInterface|null $reviewed_at
 */
class QuestionProposal extends Model
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';

    /** Ile propozycji jeden gracz może mieć jednocześnie w kolejce. */
    public const MAX_PENDING = 5;

    protected $fillable = ['user_id', 'text', 'side', 'status', 'reject_reason', 'question_id', 'reviewed_by', 'reviewed_at'];

    protected $attributes = ['status' => self::PENDING];

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

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }
}
