{{-- WAŻNE: atrybuty <flux:sidebar> porównaj z resources/views/layouts/app/sidebar.blade.php
     w panelu. Tam, gdzie działa na telefonie, skopiuj dokładnie ten sam zestaw
     (np. collapsible="mobile" albo starsze stashable). --}}
<flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
    <flux:sidebar.header>
        <flux:brand href="{{ route('home') }}" name="{{ config('app.name') }}" />
        <flux:sidebar.collapse class="lg:hidden" />
    </flux:sidebar.header>

    <flux:sidebar.nav>
        <flux:sidebar.item icon="home" :href="route('home')" :current="request()->routeIs('home')" wire:navigate>
            {{ __('Home') }}
        </flux:sidebar.item>
        {{-- kolejne pozycje opracujemy później --}}
    </flux:sidebar.nav>

    <flux:spacer />

    @guest
        <flux:sidebar.nav>
            <flux:sidebar.item icon="arrow-right-end-on-rectangle" :href="route('login')">
                {{ __('Log in') }}
            </flux:sidebar.item>
        </flux:sidebar.nav>
    @endguest
</flux:sidebar>