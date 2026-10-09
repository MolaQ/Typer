{{-- WAŻNE: atrybuty <flux:sidebar> porównaj z resources/views/layouts/app/sidebar.blade.php
    w panelu. Tam, gdzie działa na telefonie, skopiuj dokładnie ten sam zestaw
    (np. collapsible="mobile" albo starsze stashable). --}}
    {{-- Klasa dark: elementy menu w wersji jasnej na granatowym tle (barwy Lecha). --}}
    <flux:sidebar sticky collapsible="mobile"
        class="dark border-e border-lech-950 bg-linear-to-b from-lech-900 via-lech-950 to-black text-white">
        <flux:sidebar.header>
            <x-lechtyper-logo class="text-white" />
            <flux:sidebar.collapse class="lg:hidden" />
        </flux:sidebar.header>

        <flux:sidebar.nav>
            <flux:sidebar.item icon="home" :href="route('home')" :current="request()->routeIs('home')" wire:navigate>
                {{ __('Home') }}
            </flux:sidebar.item>
            @auth
                @if (\App\Support\Players::canPlay(auth()->user()))
                    <flux:sidebar.item icon="pencil-square" :href="route('tips')" :current="request()->routeIs('tips')"
                        wire:navigate>
                        LechTYPER
                    </flux:sidebar.item>
                @endif
            @endauth
            <flux:sidebar.item icon="trophy" :href="route('results')" :current="request()->routeIs('results')"
                wire:navigate>
                {{ __('Results and tables') }}
            </flux:sidebar.item>
            <flux:sidebar.item icon="star" :href="route('hall-of-fame')"
                :current="request()->routeIs('hall-of-fame', 'team.show')" wire:navigate>
                {{ __('Hall of Fame') }}
            </flux:sidebar.item>
            <flux:sidebar.item icon="bell-alert" :href="route('system')" :current="request()->routeIs('system')"
                wire:navigate>
                {{ __('System information') }}
            </flux:sidebar.item>
            <flux:sidebar.item icon="heart" :href="route('support')" :current="request()->routeIs('support')"
                wire:navigate>
                {{ __('Premium and support') }}
            </flux:sidebar.item>
            <flux:sidebar.item icon="book-open" :href="route('rules')" :current="request()->routeIs('rules')"
                wire:navigate>
                {{ __('Rules of the game') }}
            </flux:sidebar.item>
            <flux:sidebar.item icon="question-mark-circle" :href="route('faq')" :current="request()->routeIs('faq')"
                wire:navigate>
                {{ __('FAQ') }}
            </flux:sidebar.item>
        </flux:sidebar.nav>

        <flux:spacer />

        @guest
            <flux:sidebar.nav>
                <flux:sidebar.item icon="arrow-right-end-on-rectangle" :href="route('login')">
                    {{ __('Log in') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>
            @if (Route::has('register'))
                <flux:sidebar.item icon="user-plus" :href="route('register')">{{ __('Register') }}</flux:sidebar.item>
            @endif
        @endguest
    </flux:sidebar>