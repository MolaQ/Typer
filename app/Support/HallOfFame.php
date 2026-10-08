<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Enums\League;
use App\Models\HallOfFameAward;
use App\Models\Season;
use App\Models\TrophyIcon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hall of Fame (regulamin, punkt 12): punktacja wszech czasów za sukcesy w zakończonych sezonach.
 * Wartości są konfigurowalne w panelu (tabela hall_of_fame_settings), tu są wartości domyślne.
 * Punkty ligowe mnożymy przez mnożnik poziomu: Ekstraklasa 10, I liga 9 … C klasa 1, podwórkowa 0,5.
 * Złota Liga nie daje punktów, tylko unikalne trofeum.
 */
final class HallOfFame
{
    /** @var array<string, float> */
    public const DEFAULTS = [
        // Mnożniki poziomów (League 1-11).
        'multiplier_1' => 10, 'multiplier_2' => 9, 'multiplier_3' => 8, 'multiplier_4' => 7, 'multiplier_5' => 6,
        'multiplier_6' => 5, 'multiplier_7' => 4, 'multiplier_8' => 3, 'multiplier_9' => 2, 'multiplier_10' => 1,
        'multiplier_11' => 0.5,
        // Ligi i podwórkowa (× mnożnik poziomu).
        'league_champion' => 50, 'league_second' => 30, 'league_third' => 20, 'league_promotion' => 10,
        'league_top_scorer' => 20, 'league_win' => 2, 'league_draw' => 1,
        // Puchar Polski: za wygraną w każdej rundzie, a zwycięzca finału osobno.
        'cup_round_1' => 5, 'cup_round_2' => 5, 'cup_round_3' => 5, 'cup_round_4' => 10, 'cup_round_5' => 10,
        'cup_round_6' => 15, 'cup_round_7' => 20, 'cup_round_8' => 30, 'cup_winner' => 100,
        // Ligi europejskie: zwycięzca i wygrany mecz.
        'champions_winner' => 150, 'europa_winner' => 100, 'conference_winner' => 70,
        'champions_win' => 3, 'europa_win' => 2, 'conference_win' => 1,
        // Liga Legend: etapy narastająco (16, 8, 4 najlepszych, finał, zwycięstwo).
        'legends_top16' => 10, 'legends_top8' => 20, 'legends_top4' => 40, 'legends_final' => 70, 'legends_winner' => 120,
    ];

    /** @var array<string, float>|null */
    private static ?array $values = null;

    /** @var array<int, array{users: array<int, float>, bots: array<int, float>}> */
    private static array $before = [];

    /** Wartość punktacji (z panelu albo domyślna). */
    public static function value(string $key): float
    {
        return self::values()[$key] ?? 0.0;
    }

    /** @return array<string, float> */
    public static function values(): array
    {
        if (self::$values === null) {
            $saved = Schema::hasTable('hall_of_fame_settings')
                ? DB::table('hall_of_fame_settings')->pluck('value', 'key')->map(fn($v) => (float) $v)->all()
                : [];

            self::$values = array_map('floatval', array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS)));
        }

        return self::$values;
    }

    /** Po zapisie ustawień w panelu. */
    public static function forget(): void
    {
        self::$values = null;
        self::$before = [];
    }

    /** Punkty do wyświetlenia: „12,5” albo „40” (bez rozszerzenia intl). */
    public static function format(float $points): string
    {
        return rtrim(rtrim(number_format($points, 1, ',', ' '), '0'), ',');
    }

    /** Mnożnik poziomu ligi (1-10, 11 = podwórkowa). */
    public static function multiplier(int $tier): float
    {
        return self::value('multiplier_' . $tier);
    }

    /**
     * Grupy ustawień do formularza w panelu: nagłówek => [klucz => etykieta].
     *
     * @return array<string, array<string, string>>
     */
    public static function groups(): array
    {
        $multipliers = [];
        foreach (League::cases() as $league) {
            $multipliers['multiplier_' . $league->value] = $league->label();
        }

        $cup = [];
        foreach (range(1, CupBracket::ROUNDS - 1) as $round) {
            $cup['cup_round_' . $round] = __('Win in round :round', ['round' => $round]);
        }
        $cup['cup_winner'] = __('Cup winner');

        return [
            __('League multipliers') => $multipliers,
            __('Leagues (× multiplier)') => [
                'league_champion' => __('Champion'),
                'league_second' => __('Second place'),
                'league_third' => __('Third place'),
                'league_promotion' => __('Promotion'),
                'league_top_scorer' => __('Top scorer'),
                'league_win' => __('Match won'),
                'league_draw' => __('Match drawn'),
            ],
            CompetitionType::Cup->label() => $cup,
            __('European leagues') => [
                'champions_winner' => CompetitionType::Champions->label() . ': ' . __('winner'),
                'europa_winner' => CompetitionType::Europa->label() . ': ' . __('winner'),
                'conference_winner' => CompetitionType::Conference->label() . ': ' . __('winner'),
                'champions_win' => CompetitionType::Champions->label() . ': ' . __('match won'),
                'europa_win' => CompetitionType::Europa->label() . ': ' . __('match won'),
                'conference_win' => CompetitionType::Conference->label() . ': ' . __('match won'),
            ],
            CompetitionType::Legends->label() => [
                'legends_top16' => __('Top :count', ['count' => 16]),
                'legends_top8' => __('Top :count', ['count' => 8]),
                'legends_top4' => __('Top :count', ['count' => 4]),
                'legends_final' => __('Final'),
                'legends_winner' => __('Winner'),
            ],
        ];
    }

    /**
     * Trofea do gabloty: klucz => nazwa. Mistrzostwo i król strzelców osobno dla każdego poziomu (także podwórkowej).
     *
     * @return array<string, string>
     */
    public static function trophies(): array
    {
        $out = [];
        foreach (League::cases() as $league) {
            $out['league_' . $league->value] = __('Champion: :league', ['league' => $league->label()]);
        }
        foreach (League::cases() as $league) {
            $out['top_scorer_' . $league->value] = __('Top scorer: :league', ['league' => $league->label()]);
        }
        foreach ([CompetitionType::Cup, CompetitionType::Champions, CompetitionType::Europa, CompetitionType::Conference, CompetitionType::Legends, CompetitionType::Golden] as $type) {
            $out[$type->value] = $type->label();
        }

        return $out;
    }

    /** @return array<string, string> klucz trofeum => adres ikony */
    public static function iconUrls(): array
    {
        return TrophyIcon::pluck('path', 'key')->map(fn($path) => asset('storage/' . $path))->all();
    }

    /**
     * Punkty Hall of Fame ze stanu na początku sezonu (tylko wcześniejsze sezony), żeby nie było zależności
     * kołowej: kryterium tabeli w sezonie nie zależy od wyników tego sezonu (regulamin, punkt 4).
     *
     * @return array{users: array<int, float>, bots: array<int, float>}
     */
    public static function pointsBefore(Season $season): array
    {
        return self::$before[$season->id] ??= [
            'users' => self::sumBefore($season, 'user_id'),
            'bots' => self::sumBefore($season, 'bot_id'),
        ];
    }

    /** Punkty Hall of Fame zespołu przed danym sezonem (gracz albo bot). */
    public static function teamPointsBefore(Season $season, ?int $userId, ?int $botId): float
    {
        $points = self::pointsBefore($season);

        return $userId ? ($points['users'][$userId] ?? 0.0) : ($botId ? ($points['bots'][$botId] ?? 0.0) : 0.0);
    }

    /** @return array<int, float> */
    private static function sumBefore(Season $season, string $column): array
    {
        if (!Schema::hasTable('hall_of_fame_awards')) {
            return [];
        }

        return HallOfFameAward::query()
            ->join('seasons', 'seasons.id', '=', 'hall_of_fame_awards.season_id')
            ->where('seasons.number', '<', $season->number)
            ->whereNotNull('hall_of_fame_awards.' . $column)
            ->groupBy('hall_of_fame_awards.' . $column)
            ->selectRaw('hall_of_fame_awards.' . $column . ' as owner, sum(hall_of_fame_awards.points) as total')
            ->pluck('total', 'owner')
            ->map(fn($v) => round((float) $v, 1))
            ->all();
    }
}
