{{-- Prawy panel: korzyści z premium (etap 15) i stan premium zalogowanego gracza. --}}
@php
    $user = auth()->user();
    $active = \App\Support\Premium::isActive($user);
    $benefits = [
        ['eye', __('Rival preview: their outcome while tipping is open, the exact tip after it closes')],
        ['clock', __('A default tip when you forget to tip')],
        ['chart-bar', __('Your exact place among the players')],
        ['magnifying-glass', __('Team search on the home page')],
        ['pencil-square', __('Team name change without approval')],
        ['star', __('Every payment counts towards Złota Liga')],
    ];
@endphp

<div class="space-y-3 rounded-xl border border-purple-200 bg-purple-50 p-4 dark:border-purple-500/30 dark:bg-purple-500/10">
    <div class="flex items-center gap-2">
        <flux:icon.sparkles class="size-5 text-purple-600 dark:text-purple-300" />
        <flux:heading>{{ __('Premium') }}</flux:heading>
    </div>

    @if ($active)
        <flux:text class="text-sm">
            {{ $user->premium_until ? __('Active until :date', ['date' => $user->premium_until->format('d.m.Y')]) : __('Active without an end date') }}
        </flux:text>
    @endif

    <ul class="space-y-2 text-sm">
        @foreach ($benefits as [$icon, $text])
            <li class="flex gap-2">
                <flux:icon :name="$icon" variant="micro" class="mt-0.5 shrink-0 text-purple-600 dark:text-purple-300" />
                <span>{{ $text }}</span>
            </li>
        @endforeach
    </ul>

    <flux:text class="text-xs">{{ __('Tipping is always free.') }}</flux:text>

    <flux:button size="sm" class="w-full" variant="primary" :href="route('support')" wire:navigate>
        {{ $active ? __('Extend premium') : __('From 5 zł a week') }}
    </flux:button>
</div>
