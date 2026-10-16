{{--
    Kafelek z wartością w kolorze oceny (App\Support\Tone): blue, green, yellow, red, zinc (brak danych), neutral (bez oceny).
    Użycie: <x-tone-tile :tone="\App\Support\Tone::goals($goals)">{{ $goals }}</x-tone-tile>
--}}
@props(['tone' => 'zinc'])

@php
    // Pełne wypełnienie kafelka kolorem oceny.
    $toneClasses = [
        'blue' => 'bg-sky-400 text-white ring-sky-500 dark:bg-sky-500 dark:ring-sky-400',
        'green' => 'bg-green-500 text-white ring-green-600 dark:bg-green-600 dark:ring-green-500',
        'yellow' => 'bg-yellow-300 text-yellow-950 ring-yellow-400 dark:bg-yellow-400 dark:ring-yellow-300',
        'red' => 'bg-red-500 text-white ring-red-600 dark:bg-red-600 dark:ring-red-500',
        'neutral' => 'bg-white text-zinc-800 ring-zinc-300 dark:bg-zinc-900 dark:text-zinc-100 dark:ring-zinc-600',
        'zinc' => 'bg-zinc-200 text-zinc-500 ring-zinc-300 dark:bg-zinc-700 dark:text-zinc-300 dark:ring-zinc-600',
    ];
@endphp

<span {{ $attributes->class([$toneClasses[$tone] ?? $toneClasses['zinc'], 'inline-flex min-w-9 items-center justify-center rounded-md px-2 py-0.5 text-sm font-bold tabular-nums ring-1 ring-inset']) }}>{{ $slot }}</span>
