<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pamięć policzonych bloków stron (szybkość, 15.10.2026), np. „Ostatni mistrzowie” na stronie głównej
 * albo awanse i spadki po sezonie na /results. Wszystkie wpisy mają wspólną wersję: flush() podbija ją po
 * rozliczeniu kolejki, końcu sezonu, nagrodach HoF, utworzeniu i cofnięciu sezonu, zmianie nazwy zespołu albo bota,
 * więc stare wpisy przestają być czytane (i same wygasają po TTL).
 */
class PageCache
{
    private const VERSION_KEY = 'page-cache:version';

    /** Domyślny czas życia wpisu w sekundach (zabezpieczenie, gdyby jakaś zmiana ominęła flush). */
    public const TTL = 600;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function remember(string $key, Closure $callback, int $ttl = self::TTL): mixed
    {
        return Cache::remember('page-cache:'.self::version().':'.$key, $ttl, $callback);
    }

    /** Unieważnia wszystkie wpisy, po zatwierdzeniu bieżącej transakcji (albo od razu, gdy jej nie ma). */
    public static function flush(): void
    {
        DB::afterCommit(fn () => Cache::forever(self::VERSION_KEY, self::version() + 1));
    }

    private static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }
}
