<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ustawienia desygnowania zawodników Lecha (regulamin: desygnowanie, reguły R1-R10 w trakcie ustalania).
 * Wartości domyślne są tutaj, zmiany w tabeli designation_settings (panel w przygotowaniu).
 */
class Designation
{
    /** Czyste konto: Lech nie stracił gola w całym meczu (zawodnik musiał zagrać). */
    public const CLEAN_MATCH = 0;

    /** Czyste konto: Lech nie stracił gola do zejścia zawodnika. */
    public const CLEAN_UNTIL_OFF = 1;

    /** Czyste konto: Lech nie stracił gola od wejścia zawodnika. */
    public const CLEAN_FROM_ON = 2;

    public const DEFAULTS = [
        // R1: najwyżej tylu zawodników w jednym typie rozgrywek na kolejkę.
        'max_players' => 6,
        // R7: tylu losowych zawodników dostaje bot w każdym typie rozgrywek.
        'bot_players' => 1,
        // R4: tyle punktów ataku rywala zabiera czyste konto bramkarza i obrońcy.
        'clean_sheet_goalkeeper' => 1,
        'clean_sheet_defender' => 1,
        // R4: sposób liczenia czystego konta (CLEAN_*).
        'clean_sheet_mode' => self::CLEAN_MATCH,
        // R6: czy niecała połowa też daje kolejkę blokady (0 = nie).
        'partial_half_lock' => 0,
        // Doświadczenie: cała połowa, niecała połowa, wyjściowa jedenastka.
        'exp_full_half' => 2,
        'exp_partial_half' => 1,
        'exp_start' => 1,
        // Doświadczenie: „mecz zawodnika” (bramki Lecha i rywala w czasie jego gry) jak mecz 3/1/0.
        'exp_win' => 3,
        'exp_draw' => 1,
        'exp_loss' => 0,
    ];

    /** @var array<string, int>|null */
    private static ?array $values = null;

    public static function value(string $key): int
    {
        return self::values()[$key] ?? 0;
    }

    /** @return array<string, int> */
    public static function values(): array
    {
        if (self::$values === null) {
            $saved = Schema::hasTable('designation_settings')
                ? DB::table('designation_settings')->pluck('value', 'key')->map(fn ($v) => (int) $v)->all()
                : [];

            self::$values = array_map('intval', array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS)));
        }

        return self::$values;
    }

    /** Po zapisie ustawień w panelu. */
    public static function forget(): void
    {
        self::$values = null;
    }
}
