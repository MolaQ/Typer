{{--
    Lista rywali z bilansem bezpośrednim (Moje skalpy i Czarna lista na /tips, PlayerStats::scalps) jako ranking w panelu
    `x-lech-panel`, jak Hall of Fame: miejsce, zespół z właścicielem, punkty HoF, bilans W–R–P z paskiem.
    Najpierw 20 pozycji, reszta po kliknięciu „Pokaż wszystkie” (Alpine).
    Zmienne: $rivals, $title, $subtitle, $icon, $tone ('scalps' albo 'blacklist').
--}}
@php
    $limit = 20;
    $badge = $tone === 'blacklist' ? 'bg-zinc-800 text-white dark:bg-zinc-200 dark:text-zinc-900' : 'bg-orange-500 text-white';
@endphp
<x-lech-panel :title="$title" :subtitle="$subtitle" :icon="$icon" flush>
    <x-slot:actions>
        <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-bold tabular-nums text-white">{{ count($rivals) }}</span>
    </x-slot:actions>
    <div x-data="{ all: false }">
        <div class="hidden grid-cols-[3rem_minmax(0,1fr)_7rem_10rem] gap-3 border-b border-zinc-200 bg-zinc-50 px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-zinc-500 sm:grid dark:border-zinc-700 dark:bg-white/5">
            <span>#</span><span>{{ __('Rival') }}</span><span class="text-right">{{ __('Hall of Fame') }}</span><span class="text-right">{{ __('W–D–L') }}</span>
        </div>
        <ol class="divide-y divide-zinc-100 dark:divide-white/5">
            @foreach ($rivals as $rival)
                @php
                    $games = max(1, $rival['won'] + $rival['drawn'] + $rival['lost']);
                @endphp
                <li wire:key="{{ $tone }}-{{ $loop->index }}" @if ($loop->index >= $limit) x-show="all" x-cloak @endif
                    class="grid grid-cols-[2.5rem_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 px-4 py-2.5 sm:grid-cols-[3rem_minmax(0,1fr)_7rem_10rem]">
                    <span class="{{ $loop->index < 3 ? $badge : 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300' }} grid size-8 place-items-center rounded-full text-xs font-black tabular-nums">{{ $loop->iteration }}</span>
                    <div class="min-w-0">
                        <a @if ($rival['url']) href="{{ $rival['url'] }}" wire:navigate @endif class="{{ $rival['bot'] ? 'italic' : '' }} block truncate font-semibold hover:text-lech-700 hover:underline dark:hover:text-lech-300">{{ $rival['name'] }}</a>
                        <div class="truncate text-xs text-zinc-500">
                            {{ $rival['bot'] ? __('Bot') : $rival['owner'] }}
                            <span class="sm:hidden">&middot; {{ __('Hall of Fame: :points pts', ['points' => \App\Support\HallOfFame::format($rival['hof'])]) }}</span>
                        </div>
                    </div>
                    <span class="hidden text-right tabular-nums text-zinc-600 sm:block dark:text-zinc-300">{{ \App\Support\HallOfFame::format($rival['hof']) }}</span>
                    {{-- Bilans: liczby i pasek wygranych (zielony), remisów (szary) i porażek (czerwony) --}}
                    <div class="w-28 justify-self-end sm:w-40">
                        <div class="text-right text-sm font-bold tabular-nums">
                            <span class="text-green-600">{{ $rival['won'] }}</span>–<span class="text-zinc-500">{{ $rival['drawn'] }}</span>–<span class="text-red-600">{{ $rival['lost'] }}</span>
                        </div>
                        <div class="mt-1 flex h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                            <span class="bg-green-500" style="width: {{ $rival['won'] / $games * 100 }}%"></span>
                            <span class="bg-zinc-300 dark:bg-zinc-500" style="width: {{ $rival['drawn'] / $games * 100 }}%"></span>
                            <span class="bg-red-500" style="width: {{ $rival['lost'] / $games * 100 }}%"></span>
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>
        @if (count($rivals) > $limit)
            <div class="border-t border-zinc-100 p-3 text-center dark:border-white/5">
                <flux:button size="sm" variant="ghost" x-on:click="all = ! all">
                    <span x-show="! all">{{ __('Show all (:count)', ['count' => count($rivals)]) }}</span>
                    <span x-show="all" x-cloak>{{ __('Show less') }}</span>
                </flux:button>
            </div>
        @endif
    </div>
</x-lech-panel>
