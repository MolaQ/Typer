<?php

namespace App\Support;

use App\Enums\League;
use App\Models\Competition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Barwy rozgrywek: tło (gradient bg1 -> bg2), akcent (obwódki, litery, korona, ramka trofeum) i tekst na tle.
 * Klucz to Competition::trophyKey() (league_1 … league_11, cup, champions, europa, conference, legends, golden),
 * więc barwy są spójne z trofeami. Domyślne wartości poniżej, zmiany z panelu (dashboard/competition-colors)
 * w tabeli competition_colors. Zasada: tła bez żółtego, pomarańczowego i czerwonego, złoto tylko jako akcent.
 * Użycie w widokach: style="{{ CompetitionColors::style($key) }}" i klasy comp-banner, comp-badge, comp-chip (app.css).
 */
class CompetitionColors
{
    /** @var array<string, array{bg1: string, bg2: string, accent: string, ink: string}> */
    public const DEFAULTS = [
        'league_1' => ['bg1' => '#0b2f74', 'bg2' => '#1d4ed8', 'accent' => '#ffffff', 'ink' => '#ffffff'],
        'league_2' => ['bg1' => '#075985', 'bg2' => '#0ea5e9', 'accent' => '#e0f2fe', 'ink' => '#ffffff'],
        'league_3' => ['bg1' => '#134e4a', 'bg2' => '#0d9488', 'accent' => '#ccfbf1', 'ink' => '#ffffff'],
        'league_4' => ['bg1' => '#14532d', 'bg2' => '#16a34a', 'accent' => '#dcfce7', 'ink' => '#ffffff'],
        'league_5' => ['bg1' => '#365314', 'bg2' => '#65a30d', 'accent' => '#ecfccb', 'ink' => '#ffffff'],
        'league_6' => ['bg1' => '#1f3a4d', 'bg2' => '#3d6a85', 'accent' => '#dbeafe', 'ink' => '#ffffff'],
        'league_7' => ['bg1' => '#3b2a20', 'bg2' => '#6b4f3a', 'accent' => '#ecd9bd', 'ink' => '#ffffff'],
        'league_8' => ['bg1' => '#1e293b', 'bg2' => '#475569', 'accent' => '#e2e8f0', 'ink' => '#ffffff'],
        'league_9' => ['bg1' => '#52525b', 'bg2' => '#8b8b94', 'accent' => '#fafafa', 'ink' => '#ffffff'],
        'league_10' => ['bg1' => '#c9ccd3', 'bg2' => '#eceef2', 'accent' => '#3f4654', 'ink' => '#1f2430'],
        'league_11' => ['bg1' => '#1c1c1f', 'bg2' => '#3a3a40', 'accent' => '#f5f5f0', 'ink' => '#f5f5f0'],
        'cup' => ['bg1' => '#0a1733', 'bg2' => '#1e3a8a', 'accent' => '#d7dde6', 'ink' => '#ffffff'],
        'champions' => ['bg1' => '#2e1065', 'bg2' => '#6d28d9', 'accent' => '#e7c35a', 'ink' => '#ffffff'],
        'europa' => ['bg1' => '#0f141c', 'bg2' => '#2b3442', 'accent' => '#f97316', 'ink' => '#ffffff'],
        'conference' => ['bg1' => '#022c22', 'bg2' => '#047857', 'accent' => '#a3e635', 'ink' => '#ffffff'],
        'legends' => ['bg1' => '#071a2b', 'bg2' => '#0e6e80', 'accent' => '#a5f3fc', 'ink' => '#ffffff'],
        'golden' => ['bg1' => '#000000', 'bg2' => '#1f1b16', 'accent' => '#d4af37', 'ink' => '#f5e6b3'],
    ];

    /** Rozgrywki z przerywaną („kredową”) obwódką: Liga podwórkowa. */
    public const DASHED = ['league_11'];

    /** @var array<string, array{bg1: string, bg2: string, accent: string, ink: string}>|null */
    private static ?array $cache = null;

    /**
     * Kolejność rozgrywek na stronie wyników i w panelu: ligi od Ekstraklasy do podwórkowej, Puchar Polski,
     * ligi europejskie (LM, LE, LK), Liga Legend, Złota Liga.
     *
     * @return array<int, string>
     */
    public static function order(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /** Pozycja klucza w kolejności (nieznane na końcu). */
    public static function rank(string $key): int
    {
        $index = array_search($key, self::order(), true);

        return $index === false ? 999 : (int) $index;
    }

    /** @return array<string, string> klucz => nazwa rozgrywek */
    public static function labels(): array
    {
        $labels = [];
        foreach (League::cases() as $league) {
            $labels['league_'.$league->value] = $league->label();
        }

        return $labels + [
            'cup' => 'Puchar Polski',
            'champions' => 'Liga Mistrzów',
            'europa' => 'Liga Europy',
            'conference' => 'Liga Konferencji',
            'legends' => 'Liga Legend',
            'golden' => 'Złota Liga',
        ];
    }

    /** @return array<string, array{bg1: string, bg2: string, accent: string, ink: string}> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $colors = self::DEFAULTS;

        if (Schema::hasTable('competition_colors')) {
            foreach (DB::table('competition_colors')->get() as $row) {
                $key = (string) $row->key;
                if (isset($colors[$key])) {
                    $colors[$key] = ['bg1' => (string) $row->bg1, 'bg2' => (string) $row->bg2, 'accent' => (string) $row->accent, 'ink' => (string) $row->ink];
                }
            }
        }

        return self::$cache = $colors;
    }

    /** Po zapisie w panelu. */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /** @return array{bg1: string, bg2: string, accent: string, ink: string} */
    public static function for(string $key): array
    {
        return self::all()[$key] ?? self::DEFAULTS['league_1'];
    }

    /** Zmienne CSS dla klas comp-banner, comp-badge, comp-chip. */
    public static function style(string $key): string
    {
        $c = self::for($key);

        return "--c-bg1: {$c['bg1']}; --c-bg2: {$c['bg2']}; --c-accent: {$c['accent']}; --c-ink: {$c['ink']};";
    }

    public static function dashed(string $key): bool
    {
        return in_array($key, self::DASHED, true);
    }

    public static function keyOf(Competition $competition): string
    {
        return $competition->trophyKey();
    }
}
