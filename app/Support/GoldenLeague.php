<?php

namespace App\Support;

use App\Enums\League;
use App\Models\Payment;
use App\Models\Season;
use App\Models\SeasonTeam;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Złota Liga (regulamin, punkt 10): 10 zespołów wspierających, liga każdy z każdym, bez punktów Hall of Fame.
 * Skład liczy się przy zatwierdzeniu sezonu (potem jest zamrożony): suma opłaconych wpłat (premium i cegiełki)
 * z ostatnich 12 miesięcy. Przy równej sumie wyżej ten, kto wcześniej ją osiągnął (data ostatniej wpłaty).
 * Grają tylko zespoły z listy sezonu, a wolne miejsca zajmują boty według pozycji na liście.
 */
final class GoldenLeague
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

    /**
     * Skład Złotej Ligi w sezonie (seed => id zespołu z listy). Pusta tablica, gdy nikt z listy nie wspierał
     * (wtedy, np. w sezonie 1, admin wybiera skład ręcznie).
     *
     * @return array<int, int>
     */
    public static function seats(Season $season, ?CarbonInterface $at = null): array
    {
        $teams = SeasonTeam::where('season_id', $season->id)->orderBy('position')->get(['id', 'user_id', 'bot_id', 'position']);
        $byUser = $teams->whereNotNull('user_id')->keyBy('user_id');

        $seats = [];
        foreach (self::ranking($at) as $row) {
            if (count($seats) >= League::SIZE) {
                break;
            }
            if ($team = $byUser->get($row['user_id'])) {
                $seats[count($seats) + 1] = $team->id;
            }
        }

        if ($seats === []) {
            return [];
        }

        // Braki uzupełniają boty, od najwyższej pozycji na liście.
        foreach ($teams->whereNull('user_id') as $bot) {
            if (count($seats) >= League::SIZE) {
                break;
            }
            $seats[count($seats) + 1] = $bot->id;
        }

        return $seats;
    }
}
