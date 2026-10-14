{{--
    Protokół meczu Lecha (match_events) jak w relacjach na żywo: tylko zawodnicy Lecha, kolejność wpisu.
    Czas (punkt osi MatchReport::OFF_LABELS) tylko przy zmianie, drugiej żółtej i czerwonej kartce.
    Zmienne: $events (MatchEvent z player i other), $deletable (przycisk usuwania wpisu, panel admina).
--}}
@php
    $deletable = $deletable ?? false;
    $surname = fn ($p) => $p ? \App\Support\MatchSheet::surname($p->name) : '?';
@endphp
<ol class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
    @foreach ($events as $event)
        <li class="flex items-center gap-2 py-1.5" wire:key="event-{{ $event->id }}">
            <span class="w-9 shrink-0 text-right text-xs font-semibold tabular-nums text-zinc-500">{{ $event->slot !== null ? (\App\Support\MatchReport::OFF_LABELS[$event->slot] ?? '') . "'" : '' }}</span>
            <span class="flex w-5 shrink-0 justify-center">
                @switch($event->type)
                    @case(\App\Support\MatchReport::EVENT_GOAL)
                        <x-ball class="size-4" />
                        @break
                    @case(\App\Support\MatchReport::EVENT_OWN_GOAL)
                        <x-ball own class="size-4" />
                        @break
                    @case(\App\Support\MatchReport::EVENT_CARD)
                        @if ($event->card === 'second_yellow')
                            <span class="relative inline-block h-4 w-4"><span class="absolute left-0 top-0 h-4 w-3 rounded-[2px] bg-yellow-400"></span><span class="absolute left-1 top-0 h-4 w-3 rounded-[2px] bg-red-500 ring-1 ring-white dark:ring-zinc-900"></span></span>
                        @else
                            <span class="{{ $event->card === 'red' ? 'bg-red-500' : 'bg-yellow-400' }} inline-block h-4 w-3 rounded-[2px]"></span>
                        @endif
                        @break
                    @default
                        <flux:icon.arrows-right-left variant="micro" class="text-lech-700 dark:text-lech-300" />
                @endswitch
            </span>
            <span class="min-w-0 flex-1 truncate">
                @if ($event->type === \App\Support\MatchReport::EVENT_SUB)
                    @if ($event->other)
                        <span class="font-semibold text-green-700 dark:text-green-400" title="{{ $event->other->name }}">↑ {{ $surname($event->other) }}</span>
                    @endif
                    <span class="text-red-700 dark:text-red-400" title="{{ $event->player?->name }}">↓ {{ $surname($event->player) }}</span>
                    @if (!$event->other)
                        <span class="text-xs text-zinc-500">({{ __('no substitute') }})</span>
                    @endif
                @else
                    <span class="font-semibold" title="{{ $event->player?->name }}">{{ $surname($event->player) }}</span>
                    @if ($event->type === \App\Support\MatchReport::EVENT_GOAL && $event->other)
                        <span class="text-xs text-zinc-500" title="{{ $event->other->name }}">({{ $surname($event->other) }})</span>
                    @elseif ($event->type === \App\Support\MatchReport::EVENT_OWN_GOAL)
                        <span class="text-xs text-zinc-500">({{ __('own goal') }})</span>
                    @endif
                @endif
            </span>
            @if ($deletable)
                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="deleteEvent({{ $event->id }})" :aria-label="__('Remove')" />
            @endif
        </li>
    @endforeach
</ol>
