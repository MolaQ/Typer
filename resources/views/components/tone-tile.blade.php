{{--
    Kafelek z wartością w kolorze oceny (App\Support\Tone): blue, green, yellow, red, zinc (brak danych), neutral (bez oceny).
    Użycie: <x-tone-tile :tone="\App\Support\Tone::goals($goals)">{{ $goals }}</x-tone-tile>
--}}
@props(['tone' => 'zinc'])

@php
    $toneClasses = [
        'blue' => 'bg-sky-100 text-sky-800 ring-sky-300 dark:bg-sky-500/20 dark:text-sky-200 dark:ring-sky-500/40',
        'green' => 'bg-green-100 text-green-800 ring-green-300 dark:bg-green-500/20 dark:text-green-200 dark:ring-green-500/40',
        'yellow' => 'bg-yellow-100 text-yellow-800 ring-yellow-300 dark:bg-yellow-500/20 dark:text-yellow-200 dark:ring-yellow-500/40',
        'red' => 'bg-red-100 text-red-700 ring-red-300 dark:bg-red-500/20 dark:text-red-200 dark:ring-red-500/40',
        'neutral' => 'bg-white text-zinc-800 ring-zinc-300 dark:bg-zinc-900 dark:text-zinc-100 dark:ring-zinc-600',
        'zinc' => 'bg-zinc-100 text-zinc-500 ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:ring-zinc-700',
    ];
@endphp

<span {{ $attributes->class([$toneClasses[$tone] ?? $toneClasses['zinc'], 'inline-flex min-w-9 items-center justify-center rounded-md px-2 py-0.5 text-sm font-bold tabular-nums ring-1 ring-inset']) }}>{{ $slot }}</span>
