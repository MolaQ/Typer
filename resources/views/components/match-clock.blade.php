{{--
    Zegar czasu gry (15.10.2026) zamiast minut: koło podzielone na ćwiartki osi meczu (MatchReport, punkty 0-4),
    zamalowany wycinek = czas na boisku. Puste = nie grał, ćwiartka = zszedł w 1. połowie, połowa = w przerwie,
    trzy ćwiartki = w 2. połowie, pełne = do końca (także doliczony czas, np. 90+7). Dogrywka (punkty 4-8) to drugie,
    mniejsze koło w innym kolorze, pokazywane tylko, gdy czas sięga dogrywki.
    Props: from (punkt wejścia, -1 = nie wszedł; null z Blade daje domyślne 0), to (punkt zejścia albo moment zdarzenia), title, class.
--}}
@props(['from' => 0, 'to' => 4, 'title' => null])
@php
    // Wycinek koła o środku (10,10) i promieniu 8 od ułamka $a do $b (0 = godzina 12, zgodnie z ruchem wskazówek).
    $sector = function (float $a, float $b): ?string {
        if ($b - $a <= 0) {
            return null;
        }
        if ($b - $a >= 1) {
            return 'full';
        }
        $point = fn (float $f) => round(10 + 8 * sin(2 * M_PI * $f), 3).' '.round(10 - 8 * cos(2 * M_PI * $f), 3);

        return 'M10 10 L'.$point($a).' A8 8 0 '.($b - $a > 0.5 ? 1 : 0).' 1 '.$point($b).' Z';
    };
    $played = $from >= 0 && $to > $from;
    $regular = $played ? $sector(min($from, 4) / 4, min($to, 4) / 4) : null;
    $extra = $played ? $sector(max($from - 4, 0) / 4, max($to - 4, 0) / 4) : null;
@endphp
<span {{ $attributes->class(['inline-flex shrink-0 items-center gap-0.5 align-middle']) }} @if ($title) title="{{ $title }}" @endif>
    <svg viewBox="0 0 20 20" aria-hidden="true" class="size-4 text-lech-700 dark:text-lech-300">
        <circle cx="10" cy="10" r="8.5" fill="none" stroke="currentColor" stroke-opacity="0.45" stroke-width="1.2" />
        <path d="M10 1.5 V18.5 M1.5 10 H18.5" stroke="currentColor" stroke-opacity="0.15" stroke-width="0.8" />
        @if ($regular === 'full')
            <circle cx="10" cy="10" r="8" fill="currentColor" />
        @elseif ($regular)
            <path d="{{ $regular }}" fill="currentColor" />
        @endif
    </svg>
    @if ($extra)
        <svg viewBox="0 0 20 20" aria-hidden="true" class="size-3 text-teal-600 dark:text-teal-400">
            <circle cx="10" cy="10" r="8.5" fill="none" stroke="currentColor" stroke-opacity="0.5" stroke-width="1.5" />
            @if ($extra === 'full')
                <circle cx="10" cy="10" r="8" fill="currentColor" />
            @else
                <path d="{{ $extra }}" fill="currentColor" />
            @endif
        </svg>
    @endif
</span>
