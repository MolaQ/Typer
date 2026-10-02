@auth
    {{-- Zalogowany: header na każdej szerokości --}}
    <flux:header class="sticky top-0 z-10 h-16 border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:brand href="{{ route('home') }}" name="{{ config('app.name') }}" class="ms-2 lg:hidden" />

        <flux:spacer />

        <flux:button :href="route('dashboard')" variant="ghost" size="sm" icon="squares-2x2" class="hidden sm:inline-flex">
            {{ __('Dashboard') }}
        </flux:button>

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
    {{-- Gość: wąski pasek tylko poniżej xl, z przyciskami otwierającymi panele --}}
    <div
        class="sticky top-0 z-10 flex h-16 items-center gap-2 border-b border-zinc-200 bg-zinc-50 px-4 dark:border-zinc-700 dark:bg-zinc-900 xl:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" />

        <flux:brand href="{{ route('home') }}" name="{{ config('app.name') }}" class="lg:hidden" />

        <flux:spacer />

        <flux:button :href="route('login')" variant="ghost" size="sm">{{ __('Log in') }}</flux:button>
        @if (Route::has('register'))
            <flux:button :href="route('register')" variant="primary" size="sm">{{ __('Register') }}</flux:button>
        @endif
        <flux:modal.trigger name="right-panel">
            <flux:button variant="ghost" size="sm" icon="bars-3-bottom-right" :aria-label="__('Open panel')" />
        </flux:modal.trigger>
    </div>
@endauth