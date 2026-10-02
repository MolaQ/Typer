<?php

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::public')] class extends Component {
    public function render(): View
    {
        return $this->view()->title(config('app.name'));
    }
};
?>

<div class="py-16">
    <flux:heading size="xl" level="1">{{ __('Welcome') }}</flux:heading>
    <flux:subheading>{{ __('This is the public home page.') }}</flux:subheading>
</div>