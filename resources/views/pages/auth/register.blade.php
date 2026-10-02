<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details and your team below')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Name -->
            <flux:input name="name" :label="__('Name')" :value="old('name')" type="text" required autofocus
                autocomplete="name" :placeholder="__('Full name')" />

            <!-- Email Address -->
            <flux:input name="email" :label="__('Email address')" :value="old('email')" type="email" required
                autocomplete="email" placeholder="email@example.com" />

            <flux:separator :text="__('Your team')" />

            <!-- Team name -->
            <flux:input name="team_name" :label="__('Team name')" :value="old('team_name')" type="text" required
                minlength="3" maxlength="40" :placeholder="__('Full team name')" />

            <!-- Team short name -->
            <flux:input name="team_short_name" :label="__('Team short name')" :value="old('team_short_name')"
                type="text" required minlength="3" maxlength="20" :placeholder="__('Short team name')" />

            <!-- Team abbreviation -->
            <flux:input name="team_abbr" :label="__('Team abbreviation')" :value="old('team_abbr')" type="text" required
                minlength="3" maxlength="6" class="uppercase" :placeholder="__('3 to 6 letters')" />

            <flux:separator />

            <!-- Password -->
            <flux:input name="password" :label="__('Password')" type="password" required autocomplete="new-password"
                :placeholder="__('Password')" viewable />

            <!-- Confirm Password -->
            <flux:input name="password_confirmation" :label="__('Confirm password')" type="password" required
                autocomplete="new-password" :placeholder="__('Confirm password')" viewable />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="register-button">
                    {{ __('Create account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 text-center text-sm text-zinc-600 rtl:space-x-reverse dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>