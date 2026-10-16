<?php

namespace App\Support;

use App\Enums\SeasonStatus;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Gotowe dane zakończonych sezonów (szybkość, 16.10.2026). Zakończony sezon już się nie zmienia, więc ciężkie
 * zestawienia liczymy raz i trzymamy w tabeli `season_archives` (JSON, bez obiektów). Sezon w trakcie (albo szkic)
 * liczy się na bieżąco i trafia tylko do PageCache (unieważnianej po każdej kolejce).
 * Czyszczenie: `forget()` przy końcu sezonu, ponownym przeliczeniu kolejki i cofnięciu zatwierdzenia,
 * całość poleceniem `php artisan stats:rebuild`.
 */
class SeasonArchive
{
    /** Czas życia danych sezonu w trakcie (sekundy). */
    public const LIVE_TTL = 86400;

    /** @var array<string, array<array-key, mixed>> pamięć w obrębie żądania */
    private static array $memo = [];

    /** @var array<int, string> status sezonów w obrębie żądania */
    private static array $status = [];

    /**
     * @param  Closure(): array<array-key, mixed>  $callback
     * @return array<array-key, mixed>
     */
    public static function remember(int $seasonId, string $key, Closure $callback): array
    {
        $memoKey = $seasonId.':'.$key;

        if (isset(self::$memo[$memoKey])) {
            return self::$memo[$memoKey];
        }

        if (! self::finished($seasonId)) {
            // Doba: wpis i tak znika po rozliczeniu kolejki (PageCache::flush), a liczenie sezonu w trakcie bywa ciężkie.
            return self::$memo[$memoKey] = PageCache::remember('archive:'.$memoKey, $callback, self::LIVE_TTL);
        }

        $payload = DB::table('season_archives')->where('season_id', $seasonId)->where('key', $key)->value('payload');

        if (is_string($payload)) {
            $data = json_decode($payload, true);

            if (is_array($data)) {
                return self::$memo[$memoKey] = $data;
            }
        }

        $data = $callback();
        DB::table('season_archives')->upsert(
            [['season_id' => $seasonId, 'key' => $key, 'payload' => json_encode($data, JSON_PRESERVE_ZERO_FRACTION), 'created_at' => now(), 'updated_at' => now()]],
            ['season_id', 'key'],
            ['payload', 'updated_at'],
        );

        return self::$memo[$memoKey] = $data;
    }

    /** Usuwa gotowe dane sezonu (albo wszystkich sezonów), po zatwierdzeniu bieżącej transakcji. */
    public static function forget(?int $seasonId = null): void
    {
        DB::afterCommit(function () use ($seasonId): void {
            DB::table('season_archives')->when($seasonId !== null, fn ($q) => $q->where('season_id', $seasonId))->delete();
            self::$memo = [];
            self::$status = [];
        });
    }

    private static function finished(int $seasonId): bool
    {
        return (self::$status[$seasonId] ??= (string) DB::table('seasons')->where('id', $seasonId)->value('status')) === SeasonStatus::Finished->value;
    }
}
