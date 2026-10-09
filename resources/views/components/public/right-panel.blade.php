<div class="space-y-4">
    {{-- Rywale gracza w kolejce: nad informacją o premium (tylko zalogowani gracze z zespołem). --}}
    @auth
        <livewire:pages::home.rivals-panel />
    @endauth
    {{-- 3 najnowsze newsy (link do pełnej wiadomości) --}}
    @php
        $latestNews = \Illuminate\Support\Facades\Schema::hasTable('news')
            ? \App\Models\News::published()->latest('published_at')->limit(3)->get(['id', 'title', 'published_at'])
            : collect();
    @endphp
    <flux:heading>{{ __('News') }}</flux:heading>
    @forelse ($latestNews as $item)
        <a href="{{ route('news.show', $item) }}" wire:navigate class="group block rounded-lg border-l-4 border-lech-700 bg-white px-3 py-2 shadow-xs hover:bg-lech-50 dark:bg-zinc-900 dark:hover:bg-lech-950">
            <span class="block text-sm font-semibold leading-snug text-lech-800 group-hover:underline dark:text-lech-200">{{ $item->title }}</span>
            <span class="block text-xs text-zinc-500">{{ $item->published_at->format('d.m.Y') }}</span>
        </a>
    @empty
        <flux:text>{{ __('Latest announcements will appear here.') }}</flux:text>
    @endforelse
    <flux:separator />
    <x-public.quote />
    <flux:separator />
    <x-public.premium-card />
</div>
