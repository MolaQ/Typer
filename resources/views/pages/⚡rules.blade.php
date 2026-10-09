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

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <x-page-banner :title="__('Rules of the game')" :subtitle="__('LechTYPER in a nutshell.')" />

    <x-accordion anchor="rule" :items="\App\Support\Guide::rules()" />
</div>
