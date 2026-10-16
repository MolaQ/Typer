<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Wpłata (etap 15): premium albo wsparcie (cegiełka). Kwota w groszach.
 *
 * @property int|null $user_id
 * @property string $kind premium|support
 * @property string|null $plan
 * @property int $amount
 * @property string $status pending|paid|failed
 * @property string $source p24|manual
 * @property string|null $session_id
 * @property int|null $p24_order_id
 * @property int $premium_days
 * @property Carbon|null $paid_at
 */
class Payment extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const KIND_PREMIUM = 'premium';

    public const KIND_SUPPORT = 'support';

    protected $fillable = [
        'user_id', 'kind', 'plan', 'amount', 'currency', 'status', 'source', 'session_id', 'p24_order_id',
        'premium_days', 'paid_at', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'premium_days' => 'integer',
            'p24_order_id' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePaid(Builder $query): void
    {
        $query->where('status', self::PAID);
    }

    /** Kwota do wyświetlenia: „15 zł” albo „12,50 zł”. */
    public function amountLabel(): string
    {
        return self::money($this->amount);
    }

    public static function money(int $grosze): string
    {
        return ($grosze % 100 === 0 ? number_format($grosze / 100, 0, ',', ' ') : number_format($grosze / 100, 2, ',', ' ')).' zł';
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::PAID => 'green',
            self::FAILED => 'red',
            default => 'amber',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PAID => __('Paid'),
            self::FAILED => __('Failed'),
            default => __('Pending'),
        };
    }

    public function kindLabel(): string
    {
        return $this->kind === self::KIND_PREMIUM ? __('Premium') : __('Support');
    }
}
