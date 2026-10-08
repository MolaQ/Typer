{{-- Klasa dark: jasny tekst Flux na granatowym tle. --}}
<section class="dark border-t-4 border-lech-600 bg-lech-900 text-white">
    <div class="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:grid-cols-2 sm:px-6 lg:grid-cols-4 lg:px-8">
        <div class="space-y-2">
            <flux:heading>{{ __('About') }}</flux:heading>
            <flux:text>{{ __('A short description of the site.') }}</flux:text>
        </div>

        <div class="space-y-2">
            <flux:heading>{{ __('Contact') }}</flux:heading>
            <flux:text>kontakt@example.com</flux:text>
        </div>

        <div class="space-y-2">
            <flux:heading>{{ __('Links') }}</flux:heading>
            <ul class="space-y-1">
                <li><flux:link href="#">{{ __('Privacy policy') }}</flux:link></li>
                <li><flux:link href="#">{{ __('Terms') }}</flux:link></li>
            </ul>
        </div>

        <div class="space-y-2">
            <flux:heading>{{ __('Opening hours') }}</flux:heading>
            <flux:text>{{ __('Mon–Fri, 9:00–17:00') }}</flux:text>
        </div>
    </div>
</section>