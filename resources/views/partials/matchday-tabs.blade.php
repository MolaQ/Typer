{{-- Zakładki strony „Kolejki”: kolejki sezonu i kalendarz meczów Lecha, z którego kolejki biorą mecze.
     $tab ('matchdays' albo 'calendar') zamiast routeIs, bo akcje Livewire idą innym adresem. --}}
<flux:navbar class="-mb-2 border-b border-zinc-200 dark:border-zinc-700">
    <flux:navbar.item icon="calendar" :href="route('dashboard.matchdays')"
        :current="$tab === 'matchdays'" wire:navigate>
        {{ __('Season matchdays') }}
    </flux:navbar.item>
    <flux:navbar.item icon="calendar-days" :href="route('dashboard.lech-calendar')"
        :current="$tab === 'calendar'" wire:navigate>
        {{ __('Lech calendar') }}
    </flux:navbar.item>
</flux:navbar>
