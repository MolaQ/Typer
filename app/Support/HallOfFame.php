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
 * Punkty ligowe mnożymy przez mnożnik poziomu. Wartości domyślne ułożone są tak, żeby tytuły dawały punkty
 * zgodnie z ważnością rozgrywek (pełny tytuł z meczami i awansem):
 *   Liga Legend 1000 (premie za rundy, do tego punkty Legend z kolejek) > Ekstraklasa 500 > Puchar Polski 450
 *   > MVP 400 > Złota Piłka 360 > Złote Rękawice 330 > Liga Mistrzów 300 > Liga Europy 250 > Liga Konferencji 180
 *   > I liga 168 > II liga 144 … > podwórkowa 12.
 * Złota Liga nie daje punktów, tylko unikalne trofeum (najniżej w gablocie).
 */
final class HallOfFame
{
    /** @var array<string, int|float> */
    public const DEFAULTS = [
        // Mnożniki poziomów (League 1-11).
        // Ekstraklasa wyraźnie wyżej niż reszta: między nią a I ligą są nagrody indywidualne i ligi europejskie.
        'multiplier_1' => 10, 'multiplier_2' => 2.8, 'multiplier_3' => 2.4, 'multiplier_4' => 2, 'multiplier_5' => 1.6,
        'multiplier_6' => 1.3, 'multiplier_7' => 1, 'multiplier_8' => 0.8, 'multiplier_9' => 0.6, 'multiplier_10' => 0.4,
        'multiplier_11' => 0.2,
        // Ligi i podwórkowa (× mnożnik poziomu): mistrz Ekstraklasy 500, I ligi 140 + awans 28, podwórkowej 10 + awans 2.
        'league_champion' => 50, 'league_second' => 25, 'league_third' => 15, 'league_promotion' => 10,
        'league_top_scorer' => 10, 'league_win' => 1, 'league_draw' => 0.5,
        // Puchar Polski (zaraz po Ekstraklasie): za wygraną w każdej rundzie (razem 75), zwycięzca finału osobno (375), łącznie 450.
        'cup_round_1' => 2, 'cup_round_2' => 2, 'cup_round_3' => 3, 'cup_round_4' => 5, 'cup_round_5' => 8,
        'cup_round_6' => 12, 'cup_round_7' => 18, 'cup_round_8' => 25, 'cup_winner' => 375,
        // Ligi europejskie: zwycięzca i wygrany mecz.
        'champions_winner' => 300, 'europa_winner' => 250, 'conference_winner' => 180,
        'champions_win' => 3, 'europa_win' => 2, 'conference_win' => 1,
        // Liga Legend: punkty Legend z każdej kolejki (do 100) razy przelicznik, na bieżąco po kolejce,
        // oraz premia za przejście każdej rundy (narastająco): runda 1 = 0, 2 = 10 … 8 = 250 (awans do finału),
        // wygrana w finale 500. Zwycięzca dostaje z premii razem 1000.
        'legends_point' => 1,
        'legends_round_1' => 0, 'legends_round_2' => 10, 'legends_round_3' => 20, 'legends_round_4' => 30,
        'legends_round_5' => 40, 'legends_round_6' => 50, 'legends_round_7' => 100, 'legends_round_8' => 250,
        'legends_winner' => 500,
        // Nagrody indywidualne sezonu (gracze i boty, przy remisie wyższe miejsce w lidze).
        'mvp' => 400, 'golden_ball' => 360, 'golden_gloves' => 330,
    ];

    /** Klucze nagród indywidualnych (te same w trofeach i w punktacji). */
    public const INDIVIDUAL = ['mvp', 'golden_ball', 'golden_gloves'];

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
                ? DB::table('hall_of_fame_settings')->pluck('value', 'key')->map(fn ($v) => (float) $v)->all()
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
        return self::value('multiplier_'.$tier);
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
            $multipliers['multiplier_'.$league->value] = $league->label();
        }

        $cup = [];
        foreach (range(1, CupBracket::ROUNDS - 1) as $round) {
            $cup['cup_round_'.$round] = __('Win in round :round', ['round' => $round]);
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
                'champions_winner' => CompetitionType::Champions->label().': '.__('winner'),
                'europa_winner' => CompetitionType::Europa->label().': '.__('winner'),
                'conference_winner' => CompetitionType::Conference->label().': '.__('winner'),
                'champions_win' => CompetitionType::Champions->label().': '.__('match won'),
                'europa_win' => CompetitionType::Europa->label().': '.__('match won'),
                'conference_win' => CompetitionType::Conference->label().': '.__('match won'),
            ],
            CompetitionType::Legends->label() => [
                'legends_point' => __('Hall of Fame points per Legend point'),
                ...collect(range(1, LegendsRanking::ROUNDS - 1))
                    ->mapWithKeys(fn (int $round) => ['legends_round_'.$round => __('Through round :round', ['round' => $round])])
                    ->all(),
                'legends_winner' => __('Winner'),
            ],
            __('Individual awards') => [
                'mvp' => __('Season MVP'),
                'golden_ball' => __('Golden Ball'),
                'golden_gloves' => __('Golden Gloves'),
            ],
        ];
    }

    /**
     * Trofea do gabloty: klucz => nazwa, w kolejności ważności (tak je pokazujemy):
     * Liga Legend, Ekstraklasa, Puchar Polski, MVP, Złota Piłka, Złote Rękawice, Liga Mistrzów, Liga Europy,
     * Liga Konferencji, ligi od I ligi do podwórkowej, królowie strzelców lig (od Ekstraklasy), Złota Liga.
     *
     * @return array<string, string>
     */
    public static function trophies(): array
    {
        $out = [
            CompetitionType::Legends->value => CompetitionType::Legends->label(),
            'league_1' => __('Champion: :league', ['league' => League::Ekstraklasa->label()]),
            CompetitionType::Cup->value => CompetitionType::Cup->label(),
            'mvp' => __('Season MVP'),
            'golden_ball' => __('Golden Ball'),
            'golden_gloves' => __('Golden Gloves'),
            CompetitionType::Champions->value => CompetitionType::Champions->label(),
            CompetitionType::Europa->value => CompetitionType::Europa->label(),
            CompetitionType::Conference->value => CompetitionType::Conference->label(),
        ];
        foreach (League::cases() as $league) {
            $out['league_'.$league->value] ??= __('Champion: :league', ['league' => $league->label()]);
        }
        foreach (League::cases() as $league) {
            $out['top_scorer_'.$league->value] = __('Top scorer: :league', ['league' => $league->label()]);
        }
        $out[CompetitionType::Golden->value] = CompetitionType::Golden->label();

        return $out;
    }

    /** Pozycja trofeum na liście ważności (do sortowania gabloty i ikon w rankingu). */
    public static function trophyRank(string $key): int
    {
        $index = array_search($key, array_keys(self::trophies()), true);

        return $index === false ? PHP_INT_MAX : $index;
    }

    /** @return array<string, string> klucz trofeum => adres ikony */
    public static function iconUrls(): array
    {
        return TrophyIcon::pluck('path', 'key')->map(fn ($path) => asset('storage/'.$path))->all();
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
        if (! Schema::hasTable('hall_of_fame_awards')) {
            return [];
        }

        return HallOfFameAward::query()
            ->join('seasons', 'seasons.id', '=', 'hall_of_fame_awards.season_id')
            ->where('seasons.number', '<', $season->number)
            ->whereNotNull('hall_of_fame_awards.'.$column)
            ->groupBy('hall_of_fame_awards.'.$column)
            ->selectRaw('hall_of_fame_awards.'.$column.' as owner, sum(hall_of_fame_awards.points) as total')
            ->pluck('total', 'owner')
            ->map(fn ($v) => round((float) $v, 1))
            ->all();
    }
}
