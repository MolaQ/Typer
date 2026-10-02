<?php

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Profile settings')]
    class extends Component {
    use ProfileValidationRules;

    public string $name = '';
    public string $email = '';

    /** Aktywna zakładka: account (domyślna) albo team. Zapamiętana w adresie (?tab=team). */
    #[Url(except: 'account')]
    public string $tab = 'account';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && !Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return !Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your account details and your team')">

        {{-- Zakładki --}}
        <div role="tablist" class="my-6 flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-700">
            <button type="button" role="tab" aria-selected="{{ $tab !== 'team' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'account')" @class([
                    'flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition',
                    'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' => $tab !== 'team',
                    'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white' => $tab === 'team',
                ])>
                <flux:icon.user class="size-4" />
                {{ __('Account') }}
            </button>

            <button type="button" role="tab" aria-selected="{{ $tab === 'team' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'team')" @class([
                    'flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition',
                    'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' => $tab === 'team',
                    'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white' => $tab !== 'team',
                ])>
                <flux:icon.users class="size-4" />
                {{ __('Team') }}
            </button>
        </div>

        @if ($tab === 'team')

            {{-- Zakładka: Drużyna (osobny komponent z własnym formularzem) --}}
            <livewire:pages::settings.team />

        @else

            {{-- Zakładka: Konto --}}
            <form wire:submit="updateProfileInformation" class="w-full space-y-6">
                <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

                <div>
                    <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                    @if ($this->hasUnverifiedEmail)
                        <div>
                            <flux:text class="mt-4">
                                {{ __('Your email address is unverified.') }}

                                <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                    {{ __('Click here to re-send the verification email.') }}
                                </flux:link>
                            </flux:text>

                            @if (session('status') === 'verification-link-sent')
                                <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                    {{ __('A new verification link has been sent to your email address.') }}
                                </flux:text>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="flex items-center gap-4">
                    <div class="flex items-center justify-end">
                        <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                            {{ __('Save') }}
                        </flux:button>
                    </div>
                </div>
            </form>

            @if ($this->showDeleteUser)
                <livewire:pages::settings.delete-user-form />
            @endif

        @endif
    </x-pages::settings.layout>
</section>