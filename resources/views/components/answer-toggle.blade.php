{{--
    Przycisk trójstanowy: Tak / Brak odpowiedzi / Nie (pola tej samej szerokości).
    model: ścieżka własności Livewire, np. "answers.12"; value: '1' | '0' | '' (brak odpowiedzi).
--}}
@props(['model', 'value' => '', 'disabled' => false])

@php
    $options = [['1', __('Yes'), 'green'], ['', __('No answer'), null], ['0', __('No'), 'red']];
@endphp

<flux:button.group {{ $attributes->class('w-full') }}>
    @foreach ($options as [$option, $label, $color])
        @php
            $active = (string) $value === $option;
        @endphp
        <flux:button size="sm" class="flex-1" :variant="$active ? 'primary' : 'outline'" :color="$active ? $color : null"
            wire:click="$set('{{ $model }}', '{{ $option }}')" :disabled="$disabled">{{ $label }}</flux:button>
    @endforeach
</flux:button.group>
