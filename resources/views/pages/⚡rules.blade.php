<?php

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Zasady gry (skrót regulaminu 1.0) w akordeonie. */
new #[Layout('layouts::public')] class extends Component {
    public function render(): View
    {
        return $this->view()->title(__('Rules of the game'));
    }
}; ?>

<div class="mx-auto w-full max-w-3xl space-y-6">
    <div class="space-y-1">
        <flux:heading size="xl" level="1">{{ __('Rules of the game') }}</flux:heading>
        <flux:subheading>{{ __('LechTYPER in a nutshell.') }}</flux:subheading>
    </div>

    <x-accordion :items="\App\Support\Guide::rules()" />
</div>
