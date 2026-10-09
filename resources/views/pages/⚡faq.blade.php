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

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <x-page-banner :title="__('FAQ')" :subtitle="__('LechTYPER in a nutshell.')" />

    <x-accordion anchor="faq" :items="\App\Support\Guide::faq()" />
</div>
