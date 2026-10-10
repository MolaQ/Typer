{{-- Nagłówek strony publicznej (zalogowani i goście, na każdej szerokości) z globalną wyszukiwarką. --}}
@auth
    <flux:header class="lech-bar-shadow sticky top-0 z-20 h-16 border-b-2 border-lech-700 bg-white/90 backdrop-blur dark:border-lech-500 dark:bg-zinc-900/90">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <x-lechtyper-logo class="ms-2 lg:hidden" />

        <flux:spacer />

        {{-- Wyszukiwarka: po lewej stronie przycisku panelu administracyjnego --}}
        <livewire:pages::home.global-search />

        @can(\App\Enums\Permission::DashboardAccess->value)
            <flux:button :href="route('dashboard')" variant="ghost" size="sm" icon="squares-2x2" class="hidden sm:inline-flex">
                {{ __('Dashboard') }}
            </flux:button>
        @endcan

        <flux:modal.trigger name="right-panel">
            <flux:button variant="ghost" size="sm" icon="bars-3-bottom-right" class="xl:hidden"
                :aria-label="__('Open panel')" />
        </flux:modal.trigger>

        <flux:dropdown position="bottom" align="end">
            <flux:profile :name="auth()->user()->name" />

            <flux:menu>
                <flux:menu.item :href="route('profile.edit')" icon="cog">{{ __('Settings') }}</flux:menu.item>
                <flux:menu.separator />
                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                        {{ __('Log out') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:header>
@else
    {{-- Gość: pasek z wyszukiwarką, logowaniem i przyciskiem prawego panelu --}}
    <div
        class="lech-bar-shadow sticky top-0 z-20 flex h-16 items-center gap-2 border-b-2 border-lech-700 bg-white/90 px-4 backdrop-blur dark:border-lech-500 dark:bg-zinc-900/90">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" />

        <x-lechtyper-logo class="lg:hidden" />

        <flux:spacer />

        <livewire:pages::home.global-search />

        <flux:button :href="route('login')" variant="ghost" size="sm">{{ __('Log in') }}</flux:button>
        @if (Route::has('register'))
            <flux:button :href="route('register')" variant="primary" size="sm" class="hidden sm:inline-flex">{{ __('Register') }}</flux:button>
        @endif
        <flux:modal.trigger name="right-panel">
            <flux:button variant="ghost" size="sm" icon="bars-3-bottom-right" class="xl:hidden" :aria-label="__('Open panel')" />
        </flux:modal.trigger>
    </div>
@endauth
