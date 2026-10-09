{{--
    Gwiazdki odpowiedzi na 5 pytań jednej strony zestawu (App\Support\Rivals::answerStars).
    Tryb count: tyle niebieskich gwiazdek, ile odpowiedzi (bez pozycji), „−” gdy brak.
    Pozostałe tryby: gwiazdka na pytanie 1-5. Szara = bez odpowiedzi, niebieska = odpowiedział,
    zielona / czerwona = odpowiedź tak / nie (przed wynikami, premium) albo trafiona / błędna (po wynikach).
--}}
@props(['states' => [], 'mode' => 'given'])

@php
    $states = array_values($states ?? []);
    $given = count(array_filter($states, fn ($s) => $s !== 'none'));
    $colors = [
        'none' => 'text-zinc-300 dark:text-zinc-600',
        'given' => 'text-sky-500',
        'yes' => 'text-green-500',
        'no' => 'text-red-500',
        'correct' => 'text-green-500',
        'wrong' => 'text-red-500',
    ];
    $titles = [
        'none' => __('No answer'),
        'given' => __('Answered'),
        'yes' => __('Answered yes'),
        'no' => __('Answered no'),
        'correct' => __('Correct answer'),
        'wrong' => __('Wrong answer'),
    ];
@endphp

<span {{ $attributes->class('inline-flex items-center gap-0.5') }}>
    @if ($mode === 'count')
        @if ($given === 0)
            <span class="text-sm font-bold text-zinc-400" title="{{ __('No answers') }}">−</span>
        @else
            @for ($i = 1; $i <= $given; $i++)
                <flux:icon.star variant="micro" class="text-sky-500" title="{{ __('Answered') }}" />
            @endfor
        @endif
    @else
        @foreach ($states as $index => $state)
            <span title="{{ __('Question :number', ['number' => $index + 1]) }}: {{ $titles[$state] ?? '' }}">
                <flux:icon.star variant="micro" class="{{ $colors[$state] ?? $colors['none'] }}" />
            </span>
        @endforeach
    @endif
</span>
