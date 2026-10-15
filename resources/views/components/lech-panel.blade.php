{{--
    Panel w stylu strony (15.10.2026): biała karta z belką `lech-bar` (ikona, tytuł, podpis, slot „actions” po prawej).
    Wzór kart rozgrywek na /tips i tabel na /results. `flush` = treść bez wewnętrznego marginesu (tabele, listy).
    Użycie: <x-lech-panel :title="__('Records')" icon="trophy" :subtitle="...">...</x-lech-panel>
--}}
@props(['title', 'subtitle' => null, 'icon' => null, 'flush' => false])

<section {{ $attributes->class('overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900') }}>
    <div class="lech-bar flex flex-wrap items-center gap-3 px-4 py-3">
        @if ($icon)
            <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-white/10 text-white">
                <flux:icon :name="$icon" variant="mini" />
            </span>
        @endif
        <div class="min-w-0 flex-1">
            <div class="font-semibold leading-tight text-white">{{ $title }}</div>
            @if (filled($subtitle))
                <div class="text-xs text-lech-200">{{ $subtitle }}</div>
            @endif
        </div>
        @if (isset($actions) && $actions->isNotEmpty())
            <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
        @endif
    </div>
    <div @class(['p-4' => ! $flush])>
        {{ $slot }}
    </div>
</section>
