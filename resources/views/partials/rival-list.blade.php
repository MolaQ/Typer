{{--
    Lista rywali z bilansem bezpośrednim (Moje skalpy i Czarna lista na /tips, PlayerStats::scalps), 16.10.2026:
    baner z podsumowaniem (rywale, bilans łączny, mecze), podium pierwszej trójki w stylu Hall of Fame,
    dalej siatka kart z miejscem, zespołem, punktami HoF i paskiem W–R–P. Szybki filtr nazwy w Alpine (od 2 liter),
    najpierw 24 karty, reszta po „Pokaż wszystkie”. Tylko gracze (bez botów i zbanowanych).
    Zmienne: $rivals, $title, $subtitle, $icon, $tone ('scalps' albo 'blacklist').
--}}
@php
    $limit = 24;
    $won = array_sum(array_column($rivals, 'won'));
    $drawn = array_sum(array_column($rivals, 'drawn'));
    $lost = array_sum(array_column($rivals, 'lost'));
    $games = max(1, $won + $drawn + $lost);
    $podium = array_slice($rivals, 0, 3);
    $rest = array_slice($rivals, 3, null, true);
    $medal = [
        0 => ['ring' => 'ring-amber-400', 'badge' => 'bg-amber-400 text-amber-950', 'order' => 'sm:order-2 sm:-translate-y-3'],
        1 => ['ring' => 'ring-zinc-300', 'badge' => 'bg-zinc-300 text-zinc-800', 'order' => 'sm:order-1'],
        2 => ['ring' => 'ring-orange-300', 'badge' => 'bg-orange-300 text-orange-950', 'order' => 'sm:order-3'],
    ];
    // Czarna lista: wyróżniona liczba porażek, skalpy: liczba wygranych.
    $key = $tone === 'blacklist' ? 'lost' : 'won';
@endphp
<div class="flex flex-col gap-6" x-data="{ q: '', all: false, match(name) { return this.q.length < 2 || name.toLowerCase().includes(this.q.toLowerCase()) } }">
    {{-- Podsumowanie --}}
    <div class="lech-banner relative overflow-hidden rounded-2xl p-5 text-white shadow-lg">
        <flux:icon :name="$icon" class="pointer-events-none absolute -right-4 -top-4 size-36 text-white/5" />
        <div class="relative flex flex-wrap items-center justify-between gap-4">
            <div class="min-w-0 max-w-xl">
                <div class="flex items-center gap-2 text-lg font-bold"><flux:icon :name="$icon" variant="mini" />{{ $title }}</div>
                <p class="mt-1 text-sm text-white/75">{{ $subtitle }}</p>
            </div>
            <div class="grid grid-cols-3 gap-2 text-center">
                <div class="rounded-xl bg-white/10 px-4 py-2">
                    <div class="text-2xl font-black tabular-nums">{{ count($rivals) }}</div>
                    <div class="text-[10px] font-semibold uppercase tracking-wider opacity-75">{{ __('Rivals') }}</div>
                </div>
                <div class="rounded-xl bg-white/10 px-4 py-2">
                    <div class="text-2xl font-black tabular-nums">{{ $won + $drawn + $lost }}</div>
                    <div class="text-[10px] font-semibold uppercase tracking-wider opacity-75">{{ __('Matches') }}</div>
                </div>
                <div class="rounded-xl bg-white/10 px-4 py-2">
                    <div class="whitespace-nowrap text-2xl font-black tabular-nums">{{ $won }}–{{ $drawn }}–{{ $lost }}</div>
                    <div class="text-[10px] font-semibold uppercase tracking-wider opacity-75">{{ __('W–D–L') }}</div>
                </div>
            </div>
        </div>
        <div class="relative mt-4 flex h-2 overflow-hidden rounded-full bg-white/10">
            <span class="bg-green-400" style="width: {{ $won / $games * 100 }}%"></span>
            <span class="bg-white/40" style="width: {{ $drawn / $games * 100 }}%"></span>
            <span class="bg-red-400" style="width: {{ $lost / $games * 100 }}%"></span>
        </div>
    </div>

    {{-- Podium --}}
    <div class="grid gap-3 pt-3 sm:grid-cols-3 sm:items-end">
        @foreach ($podium as $i => $rival)
            @php
                $m = $medal[$i];
                $g = max(1, $rival['won'] + $rival['drawn'] + $rival['lost']);
            @endphp
            <a href="{{ $rival['url'] }}" wire:navigate wire:key="{{ $tone }}-podium-{{ $i }}"
                class="{{ $m['order'] }} lech-banner group relative overflow-hidden rounded-2xl p-4 text-white shadow-lg ring-2 {{ $m['ring'] }} transition hover:-translate-y-1">
                <span class="pointer-events-none absolute -right-2 -top-6 text-[7rem] font-black leading-none text-white/10">{{ $i + 1 }}</span>
                <div class="relative flex items-center gap-3">
                    <span class="{{ $m['badge'] }} grid size-10 shrink-0 place-items-center rounded-full text-lg font-black shadow">{{ $i + 1 }}</span>
                    <div class="min-w-0">
                        <div class="truncate text-lg font-bold group-hover:underline">{{ $rival['name'] }}</div>
                        <div class="truncate text-xs text-white/70">{{ $rival['owner'] }}</div>
                    </div>
                </div>
                <div class="relative mt-3 flex items-end justify-between gap-2">
                    <div>
                        <div class="whitespace-nowrap text-2xl font-black tabular-nums">{{ $rival['won'] }}–{{ $rival['drawn'] }}–{{ $rival['lost'] }}</div>
                        <div class="text-[10px] font-semibold uppercase tracking-wider text-white/70">{{ __('W–D–L') }}</div>
                    </div>
                    <div class="text-right">
                        <div class="whitespace-nowrap text-lg font-bold tabular-nums">{{ \App\Support\HallOfFame::format($rival['hof']) }}</div>
                        <div class="text-[10px] font-semibold uppercase tracking-wider text-white/70">{{ __('Hall of Fame') }}</div>
                    </div>
                </div>
                <div class="relative mt-2 flex h-1.5 overflow-hidden rounded-full bg-white/10">
                    <span class="bg-green-400" style="width: {{ $rival['won'] / $g * 100 }}%"></span>
                    <span class="bg-white/40" style="width: {{ $rival['drawn'] / $g * 100 }}%"></span>
                    <span class="bg-red-400" style="width: {{ $rival['lost'] / $g * 100 }}%"></span>
                </div>
            </a>
        @endforeach
    </div>

    @if ($rest !== [])
        {{-- Pozostali: filtr i siatka kart --}}
        <x-lech-panel :title="__('Other rivals')" icon="users">
            <x-slot:actions>
                <div class="w-48 sm:w-64">
                    <flux:input size="sm" x-model="q" icon="magnifying-glass" :placeholder="__('Search team (min. 2 letters)')" />
                </div>
            </x-slot:actions>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($rest as $i => $rival)
                    @php
                        $g = max(1, $rival['won'] + $rival['drawn'] + $rival['lost']);
                    @endphp
                    <a href="{{ $rival['url'] }}" wire:navigate wire:key="{{ $tone }}-{{ $i }}"
                        x-show="match(@js($rival['name'].' '.$rival['owner'])) && (all || q.length >= 2 || {{ $i < $limit ? 'true' : 'false' }})" @if ($i >= $limit) x-cloak @endif
                        class="group flex items-center gap-3 rounded-xl border border-zinc-200 bg-white p-3 transition hover:-translate-y-0.5 hover:border-lech-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-lech-500">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-lech-50 text-xs font-black tabular-nums text-lech-800 dark:bg-white/10 dark:text-lech-200">{{ $i + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="truncate font-semibold group-hover:text-lech-700 group-hover:underline dark:group-hover:text-lech-300">{{ $rival['name'] }}</span>
                                <span class="whitespace-nowrap text-sm font-bold tabular-nums">
                                    <span class="{{ $key === 'won' ? 'text-green-600' : 'text-zinc-500' }}">{{ $rival['won'] }}</span>–<span class="text-zinc-500">{{ $rival['drawn'] }}</span>–<span class="{{ $key === 'lost' ? 'text-red-600' : 'text-zinc-500' }}">{{ $rival['lost'] }}</span>
                                </span>
                            </div>
                            <div class="flex items-baseline justify-between gap-2 text-xs text-zinc-500">
                                <span class="truncate">{{ $rival['owner'] }}</span>
                                <span class="whitespace-nowrap tabular-nums">{{ __('HoF :points', ['points' => \App\Support\HallOfFame::format($rival['hof'])]) }}</span>
                            </div>
                            <div class="mt-1.5 flex h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                                <span class="bg-green-500" style="width: {{ $rival['won'] / $g * 100 }}%"></span>
                                <span class="bg-zinc-300 dark:bg-zinc-500" style="width: {{ $rival['drawn'] / $g * 100 }}%"></span>
                                <span class="bg-red-500" style="width: {{ $rival['lost'] / $g * 100 }}%"></span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
            @if (count($rest) > $limit)
                <div class="mt-4 text-center" x-show="q.length < 2">
                    <flux:button size="sm" variant="ghost" x-on:click="all = ! all">
                        <span x-show="! all">{{ __('Show all (:count)', ['count' => count($rivals)]) }}</span>
                        <span x-show="all" x-cloak>{{ __('Show less') }}</span>
                    </flux:button>
                </div>
            @endif
        </x-lech-panel>
    @endif
</div>
