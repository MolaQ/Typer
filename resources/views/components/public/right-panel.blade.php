<div class="space-y-4">
    {{-- Rywale gracza w kolejce: nad informacją o premium (tylko zalogowani gracze z zespołem). --}}
    @auth
        <livewire:pages::home.rivals-panel />
    @endauth
    {{-- Najnowsze newsy (pełne na stronie głównej) --}}
    @php
        $latestNews = \Illuminate\Support\Facades\Schema::hasTable('news')
            ? \App\Models\News::published()->latest('published_at')->limit(3)->get(['id', 'title', 'published_at'])
            : collect();
    @endphp
    <flux:heading>{{ __('News') }}</flux:heading>
    @forelse ($latestNews as $item)
        <a href="{{ route('home') }}#news-{{ $item->id }}" class="block rounded-lg px-2 py-1.5 hover:bg-lech-50 dark:hover:bg-lech-950">
            <span class="block text-sm font-medium leading-snug">{{ $item->title }}</span>
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
