<?php

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Hall of Fame (etap 14): ranking wszech czasów graczy według punktów za sukcesy (mecze na bieżąco, tytuły po sezonie),
 * z trofeami. Kliknięcie zespołu prowadzi do jego strony z gablotą i historią sezonów.
 * Sam ranking jest w komponencie pages::home.hall-of-fame-ranking (używa go też strona główna).
 */
new #[Layout('layouts::public')] class extends Component {
    public function render(): View
    {
        return $this->view()->title(__('Hall of Fame'));
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="space-y-1">
        <flux:heading size="xl" level="1">{{ __('Hall of Fame') }}</flux:heading>
        <flux:text>{{ __('All-time ranking: points for won matches and cup rounds are added after every matchday, titles, promotions and trophies at the end of the season.') }}</flux:text>
    </div>

    <livewire:pages::home.hall-of-fame-ranking />
</div>
