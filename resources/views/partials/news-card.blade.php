{{--
    Karta newsa: nagłówek z tytułem (link do pełnej wiadomości) i stopka w gradiencie sidebara.
    W stopce po lewej autor (link do profilu) i data, po prawej kciuki; liczby ocen widać po zagłosowaniu.
    Wymaga w komponencie Livewire metody vote(int $newsId, int $value).
    Zmienne: $news (z withVoteCounts i author), $mine (1, -1 albo 0), $full (pełna treść zamiast skrótu).
--}}
@php
    $full = $full ?? false;
    $base = 'inline-flex size-8 items-center justify-center rounded-full border transition';
    $white = 'border-white/70 bg-white text-zinc-500';
    $upClass = $mine === 1 ? 'border-green-500 bg-green-600 text-white' : $white;
    $downClass = $mine === -1 ? 'border-red-500 bg-red-600 text-white' : $white;
@endphp
<article id="news-{{ $news->id }}" wire:key="news-card-{{ $news->id }}"
    class="lech-bar-shadow flex scroll-mt-20 flex-col overflow-hidden rounded-2xl border border-lech-950/20 bg-white dark:border-lech-900 dark:bg-zinc-900">
    <header class="lech-bar px-5 py-3">
        @if ($full)
            <h1 class="text-xl font-bold leading-snug">{{ $news->title }}</h1>
        @else
            <h3 class="text-base font-bold leading-snug">
                <a href="{{ route('news.show', $news) }}" wire:navigate class="text-white decoration-lech-300 underline-offset-4 hover:text-lech-200 hover:underline">{{ $news->title }}</a>
            </h3>
        @endif
    </header>

    <div class="{{ $full ? '' : 'line-clamp-5' }} flex-1 whitespace-pre-line px-5 py-4 text-sm text-zinc-700 dark:text-zinc-300">{{ $news->body }}</div>

    @if (!$full)
        <div class="px-5 pb-3">
            <a href="{{ route('news.show', $news) }}" wire:navigate class="text-xs font-semibold text-lech-700 hover:underline dark:text-lech-300">{{ __('Read more') }}</a>
        </div>
    @endif

    <footer class="lech-bar flex flex-wrap items-center justify-between gap-3 px-5 py-2.5 text-xs">
        <div class="flex min-w-0 items-center gap-1.5 text-lech-100">
            @if ($news->author)
                <a href="{{ route('team.show', $news->author) }}" wire:navigate class="truncate font-semibold text-white hover:text-lech-200 hover:underline">{{ $news->author->name }}</a>
                <span aria-hidden="true">·</span>
            @endif
            <time datetime="{{ $news->published_at->toIso8601String() }}">{{ $news->published_at->format('d.m.Y H:i') }}</time>
        </div>

        <div class="flex items-center gap-2">
            @if ($mine !== 0)
                <span class="tabular-nums text-lech-100">
                    <span class="font-semibold text-green-400">{{ $news->up_count }}</span> {{ __('up') }},
                    <span class="font-semibold text-red-400">{{ $news->down_count }}</span> {{ __('down') }}
                </span>
            @endif
            @auth
                <button type="button" wire:click="vote({{ $news->id }}, 1)" class="{{ $base }} {{ $upClass }} cursor-pointer hover:scale-110"
                    aria-label="{{ __('Thumbs up') }}" aria-pressed="{{ $mine === 1 ? 'true' : 'false' }}">
                    <flux:icon.hand-thumb-up :variant="$mine === 1 ? 'solid' : 'outline'" class="size-4" />
                </button>
                <button type="button" wire:click="vote({{ $news->id }}, -1)" class="{{ $base }} {{ $downClass }} cursor-pointer hover:scale-110"
                    aria-label="{{ __('Thumbs down') }}" aria-pressed="{{ $mine === -1 ? 'true' : 'false' }}">
                    <flux:icon.hand-thumb-down :variant="$mine === -1 ? 'solid' : 'outline'" class="size-4" />
                </button>
            @else
                <a href="{{ route('login') }}" class="{{ $base }} {{ $white }}" title="{{ __('Log in to rate') }}" aria-label="{{ __('Log in to rate') }}">
                    <flux:icon.hand-thumb-up class="size-4" />
                </a>
                <a href="{{ route('login') }}" class="{{ $base }} {{ $white }}" title="{{ __('Log in to rate') }}" aria-label="{{ __('Log in to rate') }}">
                    <flux:icon.hand-thumb-down class="size-4" />
                </a>
            @endauth
        </div>
    </footer>
</article>
