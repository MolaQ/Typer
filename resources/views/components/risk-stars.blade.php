{{-- Ryzyko w zestawie pytań: liczba odpowiedzi (0-5) na pytania jednej strony jako gwiazdki. --}}
@props(['count' => 0, 'label' => ''])

<span {{ $attributes->class('inline-flex items-center gap-0.5') }} title="{{ $label }}: {{ $count }}/5">
    @if ($label)
        <span class="me-1 text-xs text-zinc-500">{{ $label }}</span>
    @endif
    @for ($i = 1; $i <= 5; $i++)
        @if ($i <= $count)
            <flux:icon.star variant="micro" class="text-amber-500" />
        @else
            <flux:icon.star variant="micro" class="text-zinc-300 dark:text-zinc-600" />
        @endif
    @endfor
</span>
