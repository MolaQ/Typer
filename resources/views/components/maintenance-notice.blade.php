{{--
    Przypomnienie dla admina, że strona jest zamknięta (tryb konserwacji, App\Support\Maintenance):
    pływająca plakietka na dole ekranu z linkiem do ustawień. Inni użytkownicy i tak widzą przerwę techniczną.
--}}
@if (\App\Support\Maintenance::isOn())
    <a href="{{ route('dashboard.maintenance') }}" wire:navigate
        class="fixed bottom-3 left-1/2 z-[60] flex -translate-x-1/2 items-center gap-2 rounded-full bg-amber-500 px-4 py-1.5 text-xs font-bold text-white shadow-lg ring-2 ring-white hover:bg-amber-600 dark:ring-zinc-900">
        <flux:icon.wrench-screwdriver variant="micro" />
        {{ __('Maintenance mode: only admins see the site') }}
    </a>
@endif
