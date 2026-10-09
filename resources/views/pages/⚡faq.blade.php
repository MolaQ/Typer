<?php

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Najczęstsze pytania (FAQ) w akordeonie. */
new #[Layout('layouts::public')] class extends Component {
    public function render(): View
    {
        return $this->view()->title(__('FAQ'));
    }
}; ?>

<div class="mx-auto w-full max-w-3xl space-y-6">
    <div class="space-y-1">
        <flux:heading size="xl" level="1">{{ __('FAQ') }}</flux:heading>
        <flux:subheading>{{ __('LechTYPER in a nutshell.') }}</flux:subheading>
    </div>

    <x-accordion anchor="faq" :items="\App\Support\Guide::faq()" />
</div>
