{{--
    Przycisk trójstanowy: Tak / Brak odpowiedzi / Nie (pola tej samej szerokości).
    model: ścieżka własności Livewire, np. "answers.12"; value: '1' | '0' | '' (brak odpowiedzi).
    result: poprawna odpowiedź (true/false) po rozliczeniu kolejki; wtedy wybrana odpowiedź gracza jest
    zielona (dobra) albo czerwona (zła). Bez wyniku wybór podświetla się na niebiesko jak baner strony głównej.
--}}
@props(['model', 'value' => '', 'disabled' => false, 'result' => null])

@php
    $options = [['1', __('Yes')], ['', __('No answer')], ['0', __('No')]];
@endphp

<flux:button.group {{ $attributes->class('w-full') }}>
    @foreach ($options as [$option, $label])
        @php
            $active = (string) $value === $option;
            $color = null;
            $class = 'flex-1 transition';

            if ($active && $option !== '') {
                if ($result === null) {
                    $color = 'blue';
                    $class .= ' shadow-md shadow-blue-500/40 ring-2 ring-blue-300 dark:ring-blue-500/60';
                } else {
                    $hit = ($option === '1') === (bool) $result;
                    $color = $hit ? 'green' : 'red';
                }
                $class .= ' disabled:opacity-100';
            } elseif ($active) {
                $color = 'zinc';
            }
        @endphp
        <flux:button size="sm" :class="$class" :variant="$active ? 'primary' : 'outline'" :color="$color"
            wire:click="$set('{{ $model }}', '{{ $option }}')" :disabled="$disabled">{{ $label }}</flux:button>
    @endforeach
</flux:button.group>
