<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprawa w Biurze skarg i wniosków (App\Support\Office): odwołanie od bana albo statusu Inactive, wniosek o zmianę
 * nazwy bota (premium, najpierw głosowanie graczy) albo inna sprawa do admina.
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property int|null $bot_id
 * @property string|null $bot_name
 * @property string|null $old_bot_name
 * @property string $body
 * @property string $status
 * @property int $votes_up
 * @property int $votes_down
 * @property string|null $admin_reply
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 * @property CarbonInterface|null $created_at
 */
class OfficeRequest extends Model
{
    public const BAN_APPEAL = 'ban_appeal';

    public const INACTIVE_APPEAL = 'inactive_appeal';

    public const BOT_RENAME = 'bot_rename';

    public const OTHER = 'other';

    public const TYPES = [self::BAN_APPEAL, self::INACTIVE_APPEAL, self::BOT_RENAME, self::OTHER];

    /** Głosowanie graczy (tylko wnioski o zmianę nazwy bota). */
    public const VOTING = 'voting';

    /** Czeka na decyzję admina. */
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    /** Przepadł w głosowaniu graczy (nie trafił do admina). */
    public const VOTE_FAILED = 'vote_failed';

    /** Sprawy otwarte (jeszcze bez decyzji). */
    public const OPEN = [self::VOTING, self::PENDING];

    protected $fillable = ['user_id', 'type', 'bot_id', 'bot_name', 'old_bot_name', 'body', 'status', 'votes_up', 'votes_down', 'admin_reply', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return [
            'votes_up' => 'integer',
            'votes_down' => 'integer',
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
     * @param  Builder<OfficeRequest>  $query
     * @return Builder<OfficeRequest>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }

    /**
     * @param  Builder<OfficeRequest>  $query
     * @return Builder<OfficeRequest>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function votes(): int
    {
        return $this->votes_up + $this->votes_down;
    }

    /** Udział głosów za w procentach (0, gdy brak głosów). */
    public function approval(): int
    {
        return $this->votes() > 0 ? (int) round($this->votes_up / $this->votes() * 100) : 0;
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::BAN_APPEAL => __('Ban appeal'),
            self::INACTIVE_APPEAL => __('Inactive status appeal'),
            self::BOT_RENAME => __('Bot rename request'),
            default => __('Other complaint or request'),
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::VOTING => __('Players are voting'),
            self::PENDING => __('Waiting for the administrator'),
            self::ACCEPTED => __('Accepted'),
            self::REJECTED => __('Rejected'),
            default => __('Rejected in the vote'),
        };
    }

    /** Kolor plakietki statusu (Flux badge). */
    public function statusColor(): string
    {
        return match ($this->status) {
            self::VOTING => 'sky',
            self::PENDING => 'amber',
            self::ACCEPTED => 'green',
            default => 'red',
        };
    }
}
