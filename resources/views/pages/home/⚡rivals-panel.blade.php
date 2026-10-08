<?php

use App\Models\Matchday;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Support\Players;
use App\Support\Premium;
use App\Support\Rivals;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Prawy panel: rywale gracza w kolejce (App\Support\Rivals), jako rozwijane karty z rozgrywkami, typem,
 * ryzykiem (gwiazdki) i bilansem bezpośrednim (premium). Na stronie typowania pokazuje wybraną tam kolejkę
 * (zdarzenie matchday-selected), gdzie indziej bieżącą: otwartą do typowania, a gdy takiej nie ma, ostatnią.
 */
new class extends Component {
    public int $number = 0;

    /** Czy panel jest na stronie typowania (wtedy bez linku do niej). */
    public bool $onTips = false;

    public function mount(): void
    {
        $this->onTips = request()->routeIs('tips');
        $requested = (int) request()->query('matchday', 0);

        if ($this->onTips && $requested > 0) {
            $this->number = $requested;
        }
    }

    #[On('matchday-selected')]
    public function select(int $number): void
    {
        $this->number = $number;
        unset($this->matchday, $this->rivals);
    }

    #[Computed]
    public function matchday(): ?Matchday
    {
        $user = auth()->user();
        $season = Season::current();

        if (!$user || !$season || !Players::canPlay($user) || !SeasonTeam::where('season_id', $season->id)->where('user_id', $user->id)->exists()) {
            return null;
        }

        $matchdays = Matchday::where('season_id', $season->id)->orderBy('number')->get();

        return $matchdays->firstWhere('number', $this->number)
            ?? $matchdays->first(fn($m) => $m->isOpenForTips())
            ?? $matchdays->filter(fn($m) => $m->kickoff_at && $m->kickoff_at->isPast())->last()
            ?? $matchdays->first();
    }

    #[Computed]
    public function rivals(): array
    {
        return $this->matchday ? Rivals::forUser(auth()->user(), $this->matchday) : [];
    }
}; ?>

<div>
    @if ($this->matchday && count($this->rivals) > 0)
        @php
            $phase = \App\Support\Rivals::phase($this->matchday);
            $isPremium = \App\Support\Premium::isActive(auth()->user());
            $trophyIcons = \App\Support\HallOfFame::iconUrls();
            $phaseBadge = match ($phase) {
                \App\Support\Rivals::OPEN => ['green', __('Tipping open')],
                \App\Support\Rivals::CLOSED => ['amber', __('Tipping closed')],
                default => ['blue', __('Final')],
            };
            $formClasses = ['W' => 'bg-green-600', 'D' => 'bg-zinc-400', 'L' => 'bg-red-600'];
        @endphp
        <section class="space-y-3">
            <div class="space-y-1">
                <div class="flex items-center justify-between gap-2">
                    <flux:heading>{{ __('Your rivals') }}</flux:heading>
                    <flux:badge size="sm" :color="$phaseBadge[0]">{{ $phaseBadge[1] }}</flux:badge>
                </div>
                <flux:text size="sm">
                    {{ __('Matchday :number', ['number' => $this->matchday->number]) }}: {{ $this->matchday->fixture }}
                </flux:text>
            </div>

            <div class="space-y-2">
                @foreach ($this->rivals as $rival)
                    <details wire:key="rp-{{ $this->matchday->id }}-{{ $loop->index }}"
                        class="group overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs transition open:shadow-md dark:border-zinc-700 dark:bg-zinc-900">
                        <summary class="flex cursor-pointer list-none items-center gap-3 p-3 [&::-webkit-details-marker]:hidden">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-lech-50 dark:bg-lech-500/15">
                                @if (isset($trophyIcons[$rival['trophy']]))
                                    <img src="{{ $trophyIcons[$rival['trophy']] }}" alt="" class="size-6 object-contain">
                                @else
                                    <flux:icon.trophy variant="micro" class="text-amber-500" />
                                @endif
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[11px] font-semibold uppercase tracking-wide text-lech-700 dark:text-lech-300">{{ $rival['competition'] }}</span>
                                <span class="block truncate text-sm font-medium">{{ $rival['rival'] }}</span>
                            </span>
                            <span class="shrink-0">
                                @if ($rival['virtual'])
                                    <flux:badge size="sm" color="zinc">{{ __('Lech') }}</flux:badge>
                                @elseif ($rival['bot'] && $phase !== \App\Support\Rivals::PLAYED)
                                    <flux:badge size="sm" color="zinc">{{ __('Bot') }}</flux:badge>
                                @elseif ($rival['tip'])
                                    <flux:badge size="sm">{{ $rival['tip'] }}</flux:badge>
                                @elseif ($rival['tipped'])
                                    <flux:badge size="sm" color="green">{{ __('Tipped') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('No tip') }}</flux:badge>
                                @endif
                            </span>
                            <flux:icon.chevron-down variant="micro" class="shrink-0 text-zinc-400 transition group-open:rotate-180" />
                        </summary>

                        <div class="space-y-3 border-t border-zinc-100 bg-zinc-50/60 p-3 text-sm dark:border-zinc-700 dark:bg-zinc-800/40">
                            @if ($rival['virtual'])
                                <flux:text size="sm">{{ __('Scores as many as Lech in the real match.') }}</flux:text>
                            @elseif ($rival['bot'] && $phase !== \App\Support\Rivals::PLAYED)
                                <flux:text size="sm">{{ __('Bot: random tip at the kick-off.') }}</flux:text>
                            @else
                                {{-- Typ --}}
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-zinc-500">{{ __('Tip') }}</span>
                                    <span class="font-semibold">
                                        @if ($rival['tip'])
                                            {{ $rival['tip'] }}
                                        @elseif ($rival['outcome'])
                                            {{ $rival['outcome'] }}
                                        @elseif ($rival['tipped'])
                                            {{ __('Tipped') }}
                                        @else
                                            {{ __('No tip') }}
                                        @endif
                                    </span>
                                </div>

                                {{-- Ryzyko w pytaniach --}}
                                @if ($rival['offense'] !== null)
                                    <div class="space-y-1">
                                        <div class="text-zinc-500">{{ __('Risk in the bonus questions') }}</div>
                                        <x-risk-stars :count="$rival['offense']" :label="__('Offensive')" />
                                        <x-risk-stars :count="$rival['defense']" :label="__('Defensive')" />
                                    </div>
                                @elseif (!$isPremium)
                                    <div class="flex items-center gap-2 text-xs text-zinc-500">
                                        <x-premium-badge />
                                        {{ __('Outcome and risk while tipping is open') }}
                                    </div>
                                @endif

                                @if ($rival['bonus'])
                                    <div class="text-xs text-zinc-500">{{ $rival['bonus'] }}</div>
                                @endif
                            @endif

                            @if ($rival['score'])
                                <div class="flex items-center justify-between gap-2 rounded-lg bg-lech-700 px-3 py-1.5 text-white">
                                    <span class="text-xs">{{ __('Match') }}</span>
                                    <span class="font-bold tabular-nums">{{ $rival['score'] }}</span>
                                </div>
                            @endif

                            {{-- Bilans bezpośredni (premium) --}}
                            @if (!$rival['virtual'])
                                @if ($rival['h2h'])
                                    @php
                                        $h2h = $rival['h2h'];
                                    @endphp
                                    <div class="space-y-1.5 border-t border-zinc-200 pt-2 dark:border-zinc-700">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-zinc-500">{{ __('Head to head') }}</span>
                                            <span class="text-xs font-semibold tabular-nums">
                                                <span class="text-green-600">{{ $h2h['won'] }}</span> –
                                                <span>{{ $h2h['drawn'] }}</span> –
                                                <span class="text-red-600">{{ $h2h['lost'] }}</span>
                                            </span>
                                        </div>
                                        @forelse (array_slice($h2h['meetings'], 0, 5) as $meeting)
                                            <div class="flex items-center gap-2 text-xs">
                                                <span class="{{ $formClasses[$meeting['outcome']] }} flex size-5 shrink-0 items-center justify-center rounded text-[10px] font-bold text-white">{{ __($meeting['outcome']) }}</span>
                                                <span class="w-9 shrink-0 font-semibold tabular-nums">{{ $meeting['score'] }}</span>
                                                <span class="truncate text-zinc-500">{{ $meeting['season'] }} · {{ $meeting['competition'] }}</span>
                                            </div>
                                        @empty
                                            <div class="text-xs text-zinc-500">{{ __('First meeting') }}</div>
                                        @endforelse
                                    </div>
                                @elseif (!$isPremium)
                                    <div class="flex items-center gap-2 border-t border-zinc-200 pt-2 text-xs text-zinc-500 dark:border-zinc-700">
                                        <x-premium-badge />
                                        {{ __('Head to head and meeting history') }}
                                    </div>
                                @endif
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>

            @if (!$onTips)
                <flux:link :href="route('tips')" wire:navigate class="text-sm">{{ __('Go to tipping') }}</flux:link>
            @endif
        </section>
        <flux:separator class="mt-4" />
    @endif
</div>
