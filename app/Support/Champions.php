<?php

namespace App\Support;

use App\Enums\SeasonStatus;
use App\Models\HallOfFameAward;
use App\Models\Season;
use App\Models\SeasonTeam;

/**
 * Aktualni mistrzowie i triumfatorzy rozgrywek (tytuły z poprzedniego zakończonego sezonu), do oznaczania zespołów
 * miniaturami trofeów w barwach rozgrywek (x-champion-trophies) w tabelach, kartach meczów i oknie pojedynku.
 * Tytuł idzie za graczem (albo botem), nie za miejscem na liście. Kolejność według ważności (HallOfFame::trophyRank).
 */
final class Champions
{
    /** @var array<int, array<string, list<string>>> id sezonu => właściciel ('u12', 'b34') => klucze trofeów */
    private static array $cache = [];

    /** @var array<int, int|null> id sezonu => id poprzedniego zakończonego sezonu */
    private static array $previous = [];

    /**
     * Trofea zespołu zdobyte w poprzednim zakończonym sezonie (względem sezonu zespołu).
     *
     * @return list<string>
     */
    public static function of(?SeasonTeam $team): array
    {
        if (! $team || (! $team->user_id && ! $team->bot_id)) {
            return [];
        }

        $holders = self::holders((int) $team->season_id);

        return $holders[$team->user_id ? 'u'.$team->user_id : 'b'.$team->bot_id] ?? [];
    }

    /**
     * Właściciele tytułów dla sezonu: tylko trofea rozgrywek (ligi, puchary, Liga Legend), bez nagród indywidualnych.
     *
     * @return array<string, list<string>>
     */
    private static function holders(int $seasonId): array
    {
        if (isset(self::$cache[$seasonId])) {
            return self::$cache[$seasonId];
        }

        $previous = self::previousSeasonId($seasonId);
        $out = [];

        if ($previous !== null) {
            $keys = CompetitionColors::order();
            $awards = HallOfFameAward::where('season_id', $previous)->whereIn('trophy', $keys)->get(['user_id', 'bot_id', 'trophy']);

            foreach ($awards as $award) {
                $owner = $award->user_id ? 'u'.$award->user_id : ($award->bot_id ? 'b'.$award->bot_id : null);

                if ($owner !== null && ! in_array($award->trophy, $out[$owner] ?? [], true)) {
                    $out[$owner][] = (string) $award->trophy;
                }
            }

            foreach ($out as $owner => $trophies) {
                usort($trophies, fn (string $a, string $b) => HallOfFame::trophyRank($a) <=> HallOfFame::trophyRank($b));
                $out[$owner] = $trophies;
            }
        }

        return self::$cache[$seasonId] = $out;
    }

    private static function previousSeasonId(int $seasonId): ?int
    {
        if (array_key_exists($seasonId, self::$previous)) {
            return self::$previous[$seasonId];
        }

        $number = Season::whereKey($seasonId)->value('number');
        $id = $number === null ? null : Season::where('status', SeasonStatus::Finished->value)
            ->where('number', '<', $number)
            ->orderByDesc('number')
            ->value('id');

        return self::$previous[$seasonId] = $id === null ? null : (int) $id;
    }
}
