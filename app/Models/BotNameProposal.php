<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Propozycja nazwy bota od gracza (stopka i strona główna). Admin akceptuje ją, nadając nazwę wybranemu botowi,
 * albo odrzuca z powodem (dashboard/bot-name-proposals).
 *
 * @property int $user_id
 * @property string $text
 * @property int|null $bot_id
 * @property string $status
 * @property string|null $reject_reason
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 */
class BotNameProposal extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    /** Ile propozycji jeden gracz może mieć jednocześnie w kolejce. */
    public const MAX_PENDING = 5;

    protected $fillable = ['user_id', 'bot_id', 'text', 'status', 'reject_reason', 'reviewed_by', 'reviewed_at'];

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<Bot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * @param  Builder<BotNameProposal>  $query
     * @return Builder<BotNameProposal>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }
}
