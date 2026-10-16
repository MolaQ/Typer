<?php

use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * Potwierdzenie adresu e-mail 6-cyfrowym kodem z maila (zamiast linku).
 * Kod jest ważny godzinę, po 5 błędnych próbach trzeba odczekać minutę. Kod widzi też admin (Użytkownicy).
 */
new class extends Component {
    public string $code = '';

    public bool $sent = false;

    public function verify(): void
    {
        $user = auth()->user();

        if ($user->hasVerifiedEmail()) {
            $this->redirect(route('home'), navigate: false);

            return;
        }

        $key = 'verify-code:' . $user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('code', __('Too many attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]));

            return;
        }

        $code = preg_replace('/\D/', '', $this->code);

        if (!$user->verificationCodeMatches($code)) {
            RateLimiter::hit($key, 60);
            $this->code = '';
            $this->addError('code', __('The code is wrong or has expired.'));

            return;
        }

        RateLimiter::clear($key);
        $user->confirmEmail();
        event(new Verified($user));

        $this->redirectIntended(route('home'));
    }

    public function resend(): void
    {
        $key = 'verify-code-send:' . auth()->id();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('code', __('Too many attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]));

            return;
        }

        RateLimiter::hit($key, 300);
        auth()->user()->sendEmailVerificationNotification();
        $this->code = '';
        $this->sent = true;
    }
}; ?>

<div class="flex flex-col gap-6">
    <flux:text class="text-center">
        {{ __('We have sent a 6-digit code to :email. Enter it below to confirm your address.', ['email' => auth()->user()->email]) }}
    </flux:text>

    <form wire:submit="verify" class="flex flex-col items-center gap-4">
        <flux:otp wire:model="code" length="6" submit="auto" :label="__('Code')" label:sr-only class="mx-auto" />

        <flux:button type="submit" variant="primary" class="w-full">{{ __('Confirm') }}</flux:button>
    </form>

    @if ($sent)
        <flux:text class="text-center font-medium !text-green-600 dark:!text-green-400">
            {{ __('A new code has been sent.') }}
        </flux:text>
    @endif

    <div class="flex flex-col items-center gap-2">
        <flux:button variant="ghost" size="sm" wire:click="resend">{{ __('Send a new code') }}</flux:button>
        <flux:text size="sm" class="text-center text-zinc-500">{{ __('No email? Check the spam folder or ask the admin for the code.') }}</flux:text>
    </div>
</div>
