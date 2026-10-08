{{-- Nagłówek rozgrywek: trofeum do zdobycia, nazwa i sponsor sezonu (logo z linkiem). --}}
@props(['competition'])

@php
    $icons = \App\Support\HallOfFame::iconUrls();
    $trophy = $competition->trophyKey();
    $sponsor = $competition->sponsor;
@endphp

<div {{ $attributes->class('flex flex-wrap items-center gap-4 rounded-2xl border border-lech-100 bg-white p-4 shadow-sm dark:border-lech-900 dark:bg-zinc-900') }}>
    <div class="flex size-16 shrink-0 items-center justify-center rounded-xl bg-lech-50 dark:bg-lech-950" title="{{ __('Trophy to win') }}">
        @if (isset($icons[$trophy]))
            <img src="{{ $icons[$trophy] }}" alt="" class="size-14 object-contain">
        @else
            <flux:icon.trophy class="size-9 text-amber-500" />
        @endif
    </div>

    <div class="min-w-0 flex-1">
        <flux:heading size="lg">{{ $competition->name ?: $competition->type->label() }}</flux:heading>
        <flux:text size="sm">{{ __('Trophy to win') }}: {{ \App\Support\HallOfFame::trophies()[$trophy] ?? $competition->type->label() }}</flux:text>
    </div>

    @if ($sponsor)
        <a @if ($sponsor->url) href="{{ $sponsor->url }}" target="_blank" rel="noopener sponsored" @endif
            class="flex items-center gap-2 rounded-lg px-2 py-1 text-sm text-zinc-500 hover:bg-zinc-50 dark:hover:bg-zinc-800">
            <span class="text-xs uppercase tracking-wide">{{ __('Sponsor') }}</span>
            @if ($sponsor->logoUrl())
                <img src="{{ $sponsor->logoUrl() }}" alt="{{ $sponsor->name }}" class="h-10 max-w-32 object-contain">
            @else
                <span class="font-semibold text-zinc-800 dark:text-zinc-100">{{ $sponsor->name }}</span>
            @endif
        </a>
    @endif
</div>
