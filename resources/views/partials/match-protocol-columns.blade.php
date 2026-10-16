{{--
    Protokół meczu Lecha w kolumnach (okno pojedynku): gole z asystami, samobóje, kartki, zmiany. Kolumna jest tylko wtedy,
    gdy ma wpisy. Czas jako zegar czasu gry (x-match-clock, opis w podpowiedzi) przy zmianach, drugiej żółtej i czerwonej kartce.
    Zmienne: $events (MatchEvent z player i other).
--}}
@php
    $surname = fn ($p) => $p ? \App\Support\MatchSheet::surname($p->name) : '?';
    $groups = \App\Support\MatchReport::ordered($events)->groupBy('type');
    $titles = [
        \App\Support\MatchReport::EVENT_GOAL => __('Goals'),
        \App\Support\MatchReport::EVENT_OWN_GOAL => __('Own goals'),
        \App\Support\MatchReport::EVENT_CARD => __('Cards'),
        \App\Support\MatchReport::EVENT_SUB => __('Substitutions'),
    ];
    $columns = collect($titles)->filter(fn ($title, $type) => $groups->has($type));
    $grid = match ($columns->count()) {
        1 => '',
        2 => 'sm:grid-cols-2',
        3 => 'sm:grid-cols-3',
        default => 'sm:grid-cols-2 md:grid-cols-4',
    };
@endphp
@if ($columns->isNotEmpty())
    <div class="{{ $grid }} grid gap-x-6 gap-y-3 text-xs">
        @foreach ($columns as $type => $title)
            <div class="min-w-0 space-y-1">
                <div class="text-[10px] font-bold uppercase tracking-wider text-lech-700/80 dark:text-lech-300/80">{{ $title }}</div>
                @foreach ($groups[$type] as $event)
                    <div class="flex min-w-0 items-center gap-1.5" wire:key="pc-{{ $event->id }}">
                        <span class="flex w-4 shrink-0 justify-center">
                            @switch($type)
                                @case(\App\Support\MatchReport::EVENT_GOAL)
                                    <x-ball class="size-3.5" />
                                    @break
                                @case(\App\Support\MatchReport::EVENT_OWN_GOAL)
                                    <x-ball own class="size-3.5" />
                                    @break
                                @case(\App\Support\MatchReport::EVENT_CARD)
                                    @if ($event->card === 'second_yellow')
                                        <span class="relative inline-block h-3.5 w-3.5"><span class="absolute left-0 top-0 h-3.5 w-2.5 rounded-[2px] bg-yellow-400"></span><span class="absolute left-1 top-0 h-3.5 w-2.5 rounded-[2px] bg-red-500 ring-1 ring-white dark:ring-zinc-900"></span></span>
                                    @else
                                        <span class="{{ $event->card === 'red' ? 'bg-red-500' : 'bg-yellow-400' }} inline-block h-3.5 w-2.5 rounded-[2px]"></span>
                                    @endif
                                    @break
                                @default
                                    <flux:icon.arrows-right-left variant="micro" class="size-3.5 text-lech-700 dark:text-lech-300" />
                            @endswitch
                        </span>
                        @if ($type === \App\Support\MatchReport::EVENT_SUB)
                            {{-- Zmiana w dwóch wierszach (wchodzi, schodzi), żeby nazwiska się nie ucinały --}}
                            <span class="min-w-0 flex-1 leading-tight">
                                @if ($event->other)
                                    <span class="block truncate font-semibold text-green-700 dark:text-green-400" title="{{ $event->other->name }}">↑ {{ $surname($event->other) }}</span>
                                @endif
                                <span class="block truncate text-red-700 dark:text-red-400" title="{{ $event->player?->name }}">↓ {{ $surname($event->player) }}</span>
                            </span>
                        @else
                        <span class="min-w-0 flex-1 truncate">
                                <span class="font-semibold" title="{{ $event->player?->name }}">{{ $surname($event->player) }}</span>
                                @if ($type === \App\Support\MatchReport::EVENT_GOAL && $event->other)
                                    <span class="text-zinc-500" title="{{ $event->other->name }}">({{ $surname($event->other) }})</span>
                                @endif
                        </span>
                        @endif
                        @if ($event->slot !== null)
                            <x-match-clock :to="$event->slot" :title="\App\Support\MatchReport::phase($event->slot, long: true)" />
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
    @if ($events->whereNotNull('slot')->isNotEmpty())
        @include('partials.match-clock-legend')
    @endif
@endif
