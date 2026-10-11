@props(['title', 'text' => null])
{{--
    Zablokowana opcja premium: zamiast treści zachęta z opisem i linkiem do strony wsparcia.
    Używać przy każdej opcji tylko dla premium, żeby gracz wiedział, co dostanie.
--}}
<div {{ $attributes->class('flex flex-col items-center gap-2 rounded-xl border border-dashed border-purple-300 bg-purple-50/60 px-4 py-5 text-center dark:border-purple-500/40 dark:bg-purple-500/10') }}>
    <span class="flex size-10 items-center justify-center rounded-full bg-purple-100 text-purple-700 dark:bg-purple-500/20 dark:text-purple-300">
        <flux:icon.lock-closed class="size-5" />
    </span>
    <div class="flex items-center gap-2 font-semibold text-zinc-800 dark:text-zinc-100">
        {{ $title }}
        <x-premium-badge />
    </div>
    @if ($text)
        <p class="max-w-sm text-sm text-zinc-600 dark:text-zinc-300">{{ $text }}</p>
    @endif
    <a href="{{ route('support') }}" wire:navigate class="mt-1 inline-flex items-center gap-1 rounded-full bg-purple-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-purple-700">
        <flux:icon.sparkles variant="micro" />
        {{ __('Get premium') }}
    </a>
</div>
