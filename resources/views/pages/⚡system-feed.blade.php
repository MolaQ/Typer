<?php

use App\Models\SystemEvent;
use App\Support\SystemFeed;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Informacje systemowe: karty z wpisami tworzonymi przez system (App\Support\SystemFeed).
 * Filtrowanie kategorii działa w przeglądarce (Alpine), więc przełączanie jest natychmiastowe,
 * a wybór zapamiętujemy lokalnie.
 */
new #[Layout('layouts::public')] class extends Component {
    /** Ile ostatnich wpisów ładujemy. */
    public int $limit = 200;

    public function render(): View
    {
        return $this->view()->title(__('System information'));
    }

    #[Computed]
    public function events()
    {
        if (!Schema::hasTable('system_events')) {
            return collect();
        }

        return SystemEvent::latest('id')->limit($this->limit)->get();
    }

    /** Liczba wpisów w kategoriach (do plakietek przy przełącznikach). */
    #[Computed]
    public function counts(): array
    {
        return $this->events->countBy('category')->all();
    }

    public function more(): void
    {
        $this->limit += 200;
        unset($this->events, $this->counts);
    }
}; ?>

<div class="mx-auto w-full max-w-5xl space-y-6" x-data="{
    all: @js(array_keys(\App\Support\SystemFeed::categories())),
    off: [],
    init() {
        try { this.off = JSON.parse(localStorage.getItem('system-feed-off') || '[]') } catch (e) { this.off = [] }
        this.$watch('off', v => { try { localStorage.setItem('system-feed-off', JSON.stringify(v)) } catch (e) {} })
    },
    on(c) { return !this.off.includes(c) },
    toggle(c) { this.off = this.on(c) ? [...this.off, c] : this.off.filter(x => x !== c) },
    only(c) { this.off = this.all.filter(x => x !== c) },
    showAll() { this.off = [] },
}">
    @php
        $categories = \App\Support\SystemFeed::categories();
        // Kolory kart i przełączników (pełne nazwy klas, żeby Tailwind je zbudował).
        $tones = [
            'blue' => ['bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300', 'border-l-blue-500'],
            'green' => ['bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300', 'border-l-green-500'],
            'purple' => ['bg-purple-100 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300', 'border-l-purple-500'],
            'indigo' => ['bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300', 'border-l-indigo-500'],
            'cyan' => ['bg-cyan-100 text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-300', 'border-l-cyan-500'],
            'amber' => ['bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300', 'border-l-amber-500'],
            'yellow' => ['bg-yellow-100 text-yellow-700 dark:bg-yellow-500/15 dark:text-yellow-300', 'border-l-yellow-500'],
            'red' => ['bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300', 'border-l-red-500'],
            'zinc' => ['bg-zinc-100 text-zinc-700 dark:bg-zinc-500/15 dark:text-zinc-300', 'border-l-zinc-400'],
        ];
    @endphp

    <div class="lech-banner relative overflow-hidden rounded-2xl px-6 py-8 sm:px-8">
        <flux:icon.bell-alert aria-hidden="true" class="pointer-events-none absolute -right-3 -top-3 size-32 text-white/10" />
        <div class="relative space-y-1">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-lech-200">LechTYPER</p>
            <h1 class="text-3xl font-black tracking-tight">{{ __('System information') }}</h1>
            <p class="text-lech-100">{{ __('What is happening in the game, recorded automatically.') }}</p>
        </div>
    </div>

    {{-- Przełączniki kategorii: klik włącza albo wyłącza, dwuklik zostawia tylko tę kategorię --}}
    <div class="flex flex-wrap items-center gap-2">
        @foreach ($categories as $key => [$label, $icon, $color])
            <button type="button" wire:key="cat-{{ $key }}" @click="toggle('{{ $key }}')" @dblclick="only('{{ $key }}')"
                :class="on('{{ $key }}') ? '{{ $tones[$color][0] }} ring-1 ring-black/5' : 'bg-white text-zinc-400 line-through ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-zinc-700'"
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium shadow-xs transition">
                <flux:icon :name="$icon" variant="micro" />
                {{ $label }}
                <span class="tabular-nums text-xs opacity-70">{{ $this->counts[$key] ?? 0 }}</span>
            </button>
        @endforeach
        <flux:button size="sm" variant="ghost" x-show="off.length > 0" x-cloak @click="showAll()">{{ __('Show all') }}</flux:button>
    </div>
    <flux:text size="sm" class="-mt-3">{{ __('Click a category to hide or show it, double-click to show only that one.') }}</flux:text>

    @if ($this->events->isEmpty())
        <flux:card>
            <flux:text>{{ __('No system information yet.') }}</flux:text>
        </flux:card>
    @else
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($this->events as $event)
                @php
                    [$label, $icon, $color] = $categories[$event->category] ?? [$event->category, 'information-circle', 'zinc'];
                    $tone = $tones[$color] ?? $tones['zinc'];
                    $url = $event->url();
                @endphp
                <article wire:key="ev-{{ $event->id }}" x-show="on('{{ $event->category }}')"
                    class="{{ $tone[1] }} flex gap-3 rounded-xl border border-l-4 border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-700 dark:bg-zinc-900">
                    <span class="{{ $tone[0] }} flex size-9 shrink-0 items-center justify-center rounded-lg">
                        <flux:icon :name="$icon" variant="mini" />
                    </span>
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex items-center justify-between gap-2 text-xs text-zinc-500">
                            <span class="font-semibold uppercase tracking-wide">{{ $label }}</span>
                            <time datetime="{{ $event->created_at?->toIso8601String() }}" title="{{ $event->created_at?->format('d.m.Y H:i') }}">
                                {{ $event->created_at?->diffForHumans() }}
                            </time>
                        </div>
                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $event->text() }}</p>
                        @if ($url)
                            <a href="{{ $url }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-lech-700 hover:underline dark:text-lech-300">
                                {{ __('Show') }}
                                <flux:icon.arrow-right variant="micro" />
                            </a>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>

        @if ($this->events->count() >= $limit)
            <div class="flex justify-center">
                <flux:button wire:click="more" icon="arrow-down">{{ __('Load more') }}</flux:button>
            </div>
        @endif
    @endif
</div>
