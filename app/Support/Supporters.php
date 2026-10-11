<?php

namespace App\Support;

use App\Models\Payment;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Ranking wspierających: suma opłaconych wpłat (premium i cegiełki) z ostatnich 12 miesięcy.
 * Przy równej sumie wyżej ten, kto wcześniej ją osiągnął (data ostatniej wpłaty).
 * Strona „Wsparcie” (miejsce gracza) i panel wpłat (czołówka).
 */
final class Supporters
{
    public const MONTHS = 12;

    /**
     * Ranking wspierających: user_id => [total (grosze), last_paid_at].
     *
     * @return Collection<int, array{user_id: int, total: int, last_paid_at: string}>
     */
    public static function ranking(?CarbonInterface $at = null): Collection
    {
        $at ??= now();

        return Payment::query()
            ->paid()
            ->whereNotNull('user_id')
            ->whereBetween('paid_at', [$at->copy()->subMonths(self::MONTHS), $at])
            ->groupBy('user_id')
            ->selectRaw('user_id, sum(amount) as total, max(paid_at) as last_paid_at')
            ->orderByDesc('total')
            ->orderBy('last_paid_at')
            ->orderBy('user_id')
            ->get()
            ->map(fn ($row) => ['user_id' => (int) $row->user_id, 'total' => (int) $row->total, 'last_paid_at' => (string) $row->last_paid_at]);
    }
}
