<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">

<head>
    @include('partials.head')
</head>

<body class="min-h-screen bg-white dark:bg-zinc-800">
    <x-maintenance-notice />
    @php
        $user = auth()->user();

        // Co widać w grupach menu (pusta grupa się nie pokazuje).
        $canSeasons = $user->can(\App\Enums\Permission::SeasonList->value);

        $canRoles = $user->hasRole('Admin');
        $canRequests = $user->can(\App\Enums\Permission::TeamChangeName->value);
        $canLogs = $user->can(\App\Enums\Permission::LogView->value);
        $canNews = $user->can(\App\Enums\Permission::NewsCreate->value);
        $office = $canRoles ? \App\Models\OfficeRequest::pending()->count() : 0;

        // Powiadomienia (pilne i ostrzeżenia) jako plakietka przy Pulpicie.
        $alerts = $canSeasons ? \App\Support\AdminAlerts::count() : 0;
    @endphp

    <flux:sidebar sticky collapsible="mobile"
        class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.header>
            <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
            <flux:sidebar.collapse class="lg:hidden" />
        </flux:sidebar.header>

        {{-- Menu panelu według kolejności pracy: sezon i jego kolejki (mecz, typy, wynik), rozgrywki,
             gracze, treści, a na końcu rzadko zmieniane ustawienia. Kalendarz Lecha to zakładka „Kolejek”,
             lista kontrolna jest na pulpicie (pasek postępu sezonu). --}}
        <flux:sidebar.nav>
            <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard', 'dashboard.checklist')"
                :badge="$alerts ?: null" badge-color="red" wire:navigate>
                {{ __('Dashboard') }}
            </flux:sidebar.item>

            @if ($canSeasons)
                <flux:sidebar.group expandable :expanded="true" :heading="__('Season')" class="grid">
                    <flux:sidebar.item icon="calendar-days" :href="route('dashboard.seasons')"
                        :current="request()->routeIs('dashboard.seasons')" wire:navigate>
                        {{ __('Seasons') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="calendar" :href="route('dashboard.matchdays')"
                        :current="request()->routeIs('dashboard.matchdays', 'dashboard.lech-calendar')" wire:navigate>
                        {{ __('Matchdays') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="users" :href="route('dashboard.lech-squad')"
                        :current="request()->routeIs('dashboard.lech-squad')" wire:navigate>
                        {{ __('Lech squad') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="check-badge" :href="route('dashboard.tips')"
                        :current="request()->routeIs('dashboard.tips')" wire:navigate>
                        {{ __('Tips overview') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="flag" :href="route('dashboard.results')"
                        :current="request()->routeIs('dashboard.results')" wire:navigate>
                        {{ __('Results') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                <flux:sidebar.group expandable
                    :expanded="request()->routeIs('dashboard.season-teams', 'dashboard.competitions', 'dashboard.european-qualification', 'dashboard.fixtures')"
                    :heading="__('Competitions')" class="grid">
                    <flux:sidebar.item icon="list-bullet" :href="route('dashboard.season-teams')"
                        :current="request()->routeIs('dashboard.season-teams')" wire:navigate>
                        {{ __('Team list') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="trophy" :href="route('dashboard.competitions')"
                        :current="request()->routeIs('dashboard.competitions')" wire:navigate>
                        {{ __('Competitions') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="table-cells" :href="route('dashboard.fixtures')"
                        :current="request()->routeIs('dashboard.fixtures')" wire:navigate>
                        {{ __('Fixtures') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="globe-europe-africa" :href="route('dashboard.european-qualification')"
                        :current="request()->routeIs('dashboard.european-qualification')" wire:navigate>
                        {{ __('European qualification') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            @endif

            @if ($canRoles || $canRequests)
                <flux:sidebar.group expandable
                    :expanded="request()->routeIs('dashboard.users', 'dashboard.team-requests', 'dashboard.payments')"
                    :heading="__('Players')" class="grid">
                    @if ($canRoles)
                        <flux:sidebar.item icon="users" :href="route('dashboard.users')"
                            :current="request()->routeIs('dashboard.users')" wire:navigate>
                            {{ __('Users') }}
                        </flux:sidebar.item>
                    @endif
                    @if ($canRequests)
                        <flux:sidebar.item icon="identification" :href="route('dashboard.team-requests')"
                            :current="request()->routeIs('dashboard.team-requests')"
                            :badge="\App\Models\TeamNameChangeRequest::pending()->count() ?: null" badge-color="amber"
                            wire:navigate>
                            {{ __('Team requests') }}
                        </flux:sidebar.item>
                    @endif
                    @if ($canRoles)
                        <flux:sidebar.item icon="banknotes" :href="route('dashboard.payments')"
                            :current="request()->routeIs('dashboard.payments')" wire:navigate>
                            {{ __('Payments') }}
                        </flux:sidebar.item>
                    @endif
                </flux:sidebar.group>
            @endif

            @if ($canSeasons || $canNews || $canRoles)
                <flux:sidebar.group expandable
                    :expanded="request()->routeIs('dashboard.news', 'dashboard.office')"
                    :heading="__('Site content')" class="grid">
                    @if ($canNews)
                        <flux:sidebar.item icon="newspaper" :href="route('dashboard.news')"
                            :current="request()->routeIs('dashboard.news')" wire:navigate>
                            {{ __('News') }}
                        </flux:sidebar.item>
                    @endif
                    @if ($canRoles)
                        <flux:sidebar.item icon="inbox" :href="route('dashboard.office')"
                            :current="request()->routeIs('dashboard.office')"
                            :badge="$office ?: null" badge-color="amber" wire:navigate>
                            {{ __('Complaints and requests office') }}
                        </flux:sidebar.item>
                    @endif
                </flux:sidebar.group>
            @endif

            @if ($canSeasons || $canRoles || $canLogs)
                <flux:sidebar.group expandable
                    :expanded="request()->routeIs('dashboard.hall-of-fame', 'dashboard.designation-settings', 'dashboard.bots', 'dashboard.sponsors', 'dashboard.competition-colors', 'dashboard.maintenance', 'dashboard.roles', 'dashboard.logs')"
                    :heading="__('Game settings')" class="grid">
                    @if ($canSeasons)
                        <flux:sidebar.item icon="star" :href="route('dashboard.hall-of-fame')"
                            :current="request()->routeIs('dashboard.hall-of-fame')" wire:navigate>
                            {{ __('Hall of Fame') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="user-group" :href="route('dashboard.designation-settings')"
                            :current="request()->routeIs('dashboard.designation-settings')" wire:navigate>
                            {{ __('Line-ups') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="cpu-chip" :href="route('dashboard.bots')"
                            :current="request()->routeIs('dashboard.bots')" wire:navigate>
                            {{ __('Bots') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="megaphone" :href="route('dashboard.sponsors')"
                            :current="request()->routeIs('dashboard.sponsors')" wire:navigate>
                            {{ __('Sponsors') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="swatch" :href="route('dashboard.competition-colors')"
                            :current="request()->routeIs('dashboard.competition-colors')" wire:navigate>
                            {{ __('Competition colors') }}
                        </flux:sidebar.item>
                    @endif
                    @if ($canRoles)
                        <flux:sidebar.item icon="wrench-screwdriver" :href="route('dashboard.maintenance')"
                            :current="request()->routeIs('dashboard.maintenance')" wire:navigate>
                            {{ __('Maintenance mode') }}
                        </flux:sidebar.item>
                    @endif
                    @if ($canRoles)
                        <flux:sidebar.item icon="shield-check" :href="route('dashboard.roles')"
                            :current="request()->routeIs('dashboard.roles')" wire:navigate>
                            {{ __('Roles and permissions') }}
                        </flux:sidebar.item>
                    @endif
                    @if ($canLogs)
                        <flux:sidebar.item icon="clipboard-document-list" :href="route('dashboard.logs')"
                            :current="request()->routeIs('dashboard.logs')" wire:navigate>
                            {{ __('Change log') }}
                        </flux:sidebar.item>
                    @endif
                </flux:sidebar.group>
            @endif
        </flux:sidebar.nav>

        <flux:spacer />

        <flux:sidebar.nav>
            <flux:sidebar.item icon="globe-alt" :href="route('home')">
                {{ __('Public site') }}
            </flux:sidebar.item>
        </flux:sidebar.nav>

        <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
    </flux:sidebar>

    <!-- Mobile User Menu -->
    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:spacer />

        <flux:dropdown position="top" align="end">
            <flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down" />

            <flux:menu>
                <flux:menu.radio.group>
                    <div class="p-0 text-sm font-normal">
                        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                            <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" />

                            <div class="grid flex-1 text-start text-sm leading-tight">
                                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                            </div>
                        </div>
                    </div>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <flux:menu.radio.group>
                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                        {{ __('Settings') }}
                    </flux:menu.item>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle"
                        class="w-full cursor-pointer" data-test="logout-button">
                        {{ __('Log out') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    {{ $slot }}

    @persist('toast')
    <flux:toast.group>
        <flux:toast />
    </flux:toast.group>
    @endpersist

    <x-confirm-dialog />
    @fluxScripts
</body>

</html>