{{--
    Zwarte przyciski odpowiedzi ✓ (tak) / – (brak odpowiedzi) / ✗ (nie) na stronie typowania.
    model: ścieżka własności Livewire, np. "answers.12"; value: '1' | '0' | ''.
    result: poprawna odpowiedź po rozliczeniu (true/false): wybór gracza zielony (dobrze) albo czerwony (źle).
--}}
@props(['model', 'value' => '', 'disabled' => false, 'result' => null])

@php
    $options = [['1', '✓', __('Yes')], ['', '–', __('No answer')], ['0', '✗', __('No')]];
@endphp

<div class="flex shrink-0 gap-1" role="group">
    @foreach ($options as [$option, $icon, $label])
        @php
            $active = (string) $value === $option;
            $class = match (true) {
                !$active => 'border-zinc-300 text-zinc-400 hover:border-zinc-400 hover:text-zinc-600 dark:border-zinc-600 dark:hover:text-zinc-200',
                $option === '' => 'border-zinc-400 bg-zinc-200 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-200',
                $result !== null => ($option === '1') === (bool) $result ? 'border-green-600 bg-green-600 text-white' : 'border-red-600 bg-red-600 text-white',
                $option === '1' => 'border-green-600 bg-green-600 text-white shadow-md shadow-green-600/30',
                default => 'border-red-600 bg-red-600 text-white shadow-md shadow-red-600/30',
            };
        @endphp
        <button type="button" wire:click="$set('{{ $model }}', '{{ $option }}')" @disabled($disabled) title="{{ $label }}" aria-label="{{ $label }}"
            aria-pressed="{{ $active ? 'true' : 'false' }}"
            class="{{ $class }} flex size-9 items-center justify-center rounded-lg border text-base font-black transition active:scale-95 disabled:cursor-default">{{ $icon }}</button>
    @endforeach
</div>
