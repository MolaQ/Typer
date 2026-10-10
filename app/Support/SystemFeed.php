<?php

namespace App\Support;

use App\Models\SystemEvent;
use Illuminate\Support\Facades\Schema;

/**
 * Informacje systemowe: publiczne wpisy tworzone przez system przy ważnych zdarzeniach gry
 * (mecz dodany, wynik kolejki, gracz dołączył do gry albo ligi, ban, sezon, losowanie, trofea, news).
 * Treść zapisujemy jako angielski klucz tłumaczenia z parametrami, więc wyświetla się w języku strony.
 */
final class SystemFeed
{
    /** @return array<string, array{0: string, 1: string, 2: string}> kategoria => [etykieta, ikona, kolor] */
    public static function categories(): array
    {
        return [
            'matches' => [__('Matches'), 'calendar', 'blue'],
            'results' => [__('Results'), 'check-circle', 'green'],
            'players' => [__('Players'), 'user-plus', 'purple'],
            'leagues' => [__('Leagues'), 'table-cells', 'indigo'],
            'competitions' => [__('Draws'), 'arrows-right-left', 'cyan'],
            'seasons' => [__('Seasons'), 'flag', 'amber'],
            'trophies' => [__('Trophies'), 'trophy', 'yellow'],
            'moderation' => [__('Bans'), 'no-symbol', 'red'],
            'news' => [__('News'), 'newspaper', 'zinc'],
        ];
    }

    private static ?bool $ready = null;

    /**
     * Zapisuje wpis. $message to angielski tekst do __() z parametrami (:name itd.).
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $routeParams
     */
    public static function record(string $category, string $message, array $params = [], ?string $route = null, array $routeParams = [], ?int $userId = null): void
    {
        // Przed migracją (np. w seederach na starej bazie) po prostu nic nie zapisujemy.
        if (! (self::$ready ??= Schema::hasTable('system_events'))) {
            return;
        }

        SystemEvent::create([
            'category' => $category,
            'message' => $message,
            'params' => $params ?: null,
            'route' => $route,
            'route_params' => $routeParams ?: null,
            'user_id' => $userId,
        ]);
    }
}
