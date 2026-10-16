<?php

use App\Support\CompetitionColors;
use App\Support\CompetitionHistory;
use App\Support\HallOfFame;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Historia rozgrywek i rekordy (/history/{rozgrywki}): triumfatorzy sezon po sezonie (podium z dorobkiem, król strzelców)
 * i rekordy wszech czasów (najwięcej tytułów, goli, wygranych…) dla każdych rozgrywek i nagród indywidualnych
 * (MVP, Złota Piłka, Złote Rękawice). Dane: App\Support\CompetitionHistory, tylko zakończone sezony.
 */
new #[Layout('layouts::public')] class extends Component {
    public string $key = 'league_1';

    public function mount(?string $key_slug = null): void
    {
        $this->key = CompetitionHistory::fromSlug($key_slug) ?? 'league_1';
    }

    public function render(): View
    {
        return $this->view()->title(__('History and records') . ' – ' . (CompetitionHistory::keys()[$this->key] ?? ''));
    }

    #[Computed]
    public function honours(): array
    {
        return CompetitionHistory::honours($this->key);
    }

    #[Computed]
    public function records(): array
    {
        return CompetitionHistory::records($this->key);
    }
}; ?>

@php
    $labels = CompetitionHistory::keys();
    $colorKey = CompetitionHistory::colorKey($this->key);
    $icons = HallOfFame::iconUrls();
    $dashed = CompetitionColors::dashed($colorKey) ? 'comp-dashed' : '';
    $sections = [
        __('Leagues') => array_filter(array_keys($labels), fn ($k) => str_starts_with($k, 'league_')),
        __('Cups') => ['cup', 'champions', 'europa', 'conference', 'legends'],
        __('Individual awards') => CompetitionHistory::AWARDS,
    ];
    $medal = [1 => 'bg-amber-400 text-amber-950', 2 => 'bg-zinc-300 text-zinc-800', 3 => 'bg-orange-300 text-orange-950'];
@endphp
<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <x-page-banner :eyebrow="__('History')" :title="__('History and records')"
        :subtitle="__('Champions season by season and all-time records of every competition.')" />

    {{-- Wybór rozgrywek --}}
    <nav class="space-y-2 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        @foreach ($sections as $sectionLabel => $keys)
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="w-28 shrink-0 text-xs font-semibold uppercase tracking-wider text-zinc-500">{{ $sectionLabel }}</span>
                @foreach ($keys as $k)
                    <a href="{{ route('history', CompetitionHistory::slug($k)) }}" wire:navigate
                        class="{{ $k === $this->key ? 'border-lech-700 bg-lech-700 text-white shadow' : 'border-zinc-200 text-zinc-700 hover:border-lech-400 dark:border-zinc-700 dark:text-zinc-200' }} inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition">
                        <span class="size-2.5 rounded-full" style="{{ CompetitionColors::dotStyle(CompetitionHistory::colorKey($k)) }}"></span>
                        {{ $labels[$k] }}
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>

    {{-- Baner rozgrywek --}}
    <section class="comp-banner {{ $dashed }} rounded-2xl px-5 py-6 sm:px-8" style="{{ CompetitionColors::style($colorKey) }}">
        <div class="relative z-10 flex items-center gap-4">
            <div class="comp-badge {{ $dashed }} flex size-20 shrink-0 items-center justify-center rounded-full shadow-lg">
                @if (isset($icons[$this->key]))
                    <img src="{{ $icons[$this->key] }}" alt="" class="size-14 object-contain">
                @else
                    <flux:icon.trophy class="size-9" />
                @endif
            </div>
            <div class="min-w-0">
                <p class="comp-accent text-xs font-bold uppercase tracking-[0.2em]">{{ __('History and records') }}</p>
                <h2 class="text-3xl font-black uppercase tracking-tight sm:text-4xl">{{ $labels[$this->key] }}</h2>
                <p class="text-sm opacity-80">{{ trans_choice(':count finished season|:count finished seasons', count($this->honours), ['count' => count($this->honours)]) }}</p>
            </div>
        </div>
    </section>

    <div class="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        {{-- Triumfatorzy sezon po sezonie --}}
        <section class="space-y-3">
            <flux:heading size="lg">{{ CompetitionHistory::isAward($this->key) ? __('Winners season by season') : __('Champions season by season') }}</flux:heading>
            @forelse ($this->honours as $honour)
                <article class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900" wire:key="honour-{{ $honour['season']->id }}">
                    <div class="flex flex-col sm:flex-row">
                        <div class="comp-banner {{ $dashed }} flex shrink-0 items-center justify-center px-4 py-3 sm:w-28" style="{{ CompetitionColors::style($colorKey) }}">
                            <span class="relative z-10 text-center text-sm font-black uppercase tracking-wide">{{ $honour['season']->title }}</span>
                        </div>
                        <ol class="flex-1 divide-y divide-zinc-100 dark:divide-white/5">
                            @foreach ($honour['podium'] as $row)
                                <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-sm">
                                    <span class="{{ $medal[$row['place']] ?? 'bg-zinc-100' }} grid size-6 shrink-0 place-items-center rounded-full text-xs font-black">{{ $row['place'] }}</span>
                                    @include('partials.history-team', ['team' => $row['team'], 'strong' => $row['place'] === 1])
                                    <span class="ms-auto text-xs tabular-nums text-zinc-500">{{ $row['line'] }}</span>
                                </li>
                            @endforeach
                            @foreach ($honour['extra'] as $row)
                                <li class="flex flex-wrap items-center gap-x-3 gap-y-1 bg-zinc-50/70 px-4 py-2 text-sm dark:bg-white/5">
                                    <span class="grid size-6 shrink-0 place-items-center"><x-ball class="size-4" /></span>
                                    <span class="text-xs font-semibold uppercase text-zinc-500">{{ $row['label'] }}</span>
                                    @include('partials.history-team', ['team' => $row['team'], 'strong' => false])
                                    <span class="ms-auto text-xs tabular-nums text-zinc-500">{{ $row['line'] }}</span>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                </article>
            @empty
                <flux:card><flux:text>{{ __('No finished seasons yet.') }}</flux:text></flux:card>
            @endforelse
        </section>

        {{-- Rekordy wszech czasów --}}
        <aside class="space-y-3">
            <flux:heading size="lg">{{ __('All-time records') }}</flux:heading>
            @forelse ($this->records as $record)
                <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center gap-2 border-b border-zinc-100 px-4 py-2 dark:border-white/5">
                        <flux:icon :icon="$record['icon']" variant="micro" class="text-lech-700 dark:text-lech-300" />
                        <span class="text-xs font-bold uppercase tracking-wider text-zinc-600 dark:text-zinc-300">{{ $record['title'] }}</span>
                    </div>
                    <ol>
                        @foreach ($record['rows'] as $i => $row)
                            <li class="{{ $i === 0 ? 'bg-lech-50 dark:bg-lech-500/10' : '' }} flex items-center gap-2 px-4 py-1.5 text-sm">
                                <span class="w-4 text-xs font-bold tabular-nums text-zinc-400">{{ $i + 1 }}</span>
                                <span class="min-w-0 flex-1 truncate">@include('partials.history-team', ['team' => $row['team'], 'strong' => $i === 0])</span>
                                @if ($row['season'])
                                    <span class="text-[10px] text-zinc-500">{{ $row['season'] }}</span>
                                @endif
                                <span class="{{ $i === 0 ? 'text-lg text-lech-700 dark:text-lech-300' : '' }} font-black tabular-nums">{{ $row['value'] }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @empty
                <flux:card><flux:text>{{ __('No records yet.') }}</flux:text></flux:card>
            @endforelse
        </aside>
    </div>
</div>
