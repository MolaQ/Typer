<?php

use App\Models\News;
use App\Models\NewsVote;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Newsy na stronie głównej. Zalogowani oceniają kciukiem w górę (zielony) albo w dół (czerwony),
 * ponowny klik w ten sam kciuk cofa ocenę; nieocenione kciuki są białe. Liczby ocen widać po zagłosowaniu.
 */
new class extends Component {
    public int $perPage = 5;

    #[Computed]
    public function items()
    {
        if (!Schema::hasTable('news')) {
            return collect();
        }

        return News::published()
            ->with('author')
            ->withCount([
                'votes as up_count' => fn ($q) => $q->where('value', 1),
                'votes as down_count' => fn ($q) => $q->where('value', -1),
            ])
            ->latest('published_at')
            ->limit($this->perPage)
            ->get();
    }

    /** news_id => 1 / -1 dla zalogowanego */
    #[Computed]
    public function myVotes(): array
    {
        if (!auth()->check() || $this->items->isEmpty()) {
            return [];
        }

        return NewsVote::where('user_id', auth()->id())
            ->whereIn('news_id', $this->items->pluck('id'))
            ->pluck('value', 'news_id')
            ->all();
    }

    public function vote(int $newsId, int $value): void
    {
        abort_unless(auth()->check(), 403);
        abort_unless(in_array($value, [1, -1], true), 422);

        $news = News::published()->findOrFail($newsId);
        $existing = NewsVote::where('news_id', $news->id)->where('user_id', auth()->id())->first();

        if ($existing && $existing->value === $value) {
            $existing->delete(); // ten sam kciuk drugi raz: cofnięcie oceny
        } else {
            NewsVote::updateOrCreate(['news_id' => $news->id, 'user_id' => auth()->id()], ['value' => $value]);
        }

        unset($this->items, $this->myVotes);
    }

    public function more(): void
    {
        $this->perPage += 5;
        unset($this->items, $this->myVotes);
    }

    public function render(): View
    {
        return $this->view();
    }
}; ?>

<section class="space-y-4">
    <flux:heading size="lg">{{ __('News') }}</flux:heading>

    @forelse ($this->items as $news)
        @php
            $mine = $this->myVotes[$news->id] ?? 0;
            $base = 'inline-flex size-8 items-center justify-center rounded-full border transition';
            $white = 'border-white/70 bg-white text-zinc-500';
            $upClass = $mine === 1 ? 'border-green-500 bg-green-600 text-white' : $white;
            $downClass = $mine === -1 ? 'border-red-500 bg-red-600 text-white' : $white;
        @endphp
        {{-- Karta newsa: nagłówek i stopka w gradiencie sidebara --}}
        <article id="news-{{ $news->id }}" wire:key="home-news-{{ $news->id }}"
            class="lech-bar-shadow scroll-mt-20 overflow-hidden rounded-2xl border border-lech-950/20 bg-white dark:border-lech-900 dark:bg-zinc-900">
            <header class="lech-bar px-5 py-3">
                <h3 class="text-base font-bold leading-snug">{{ $news->title }}</h3>
            </header>

            <div class="whitespace-pre-line px-5 py-4 text-sm text-zinc-700 dark:text-zinc-300">{{ $news->body }}</div>

            <footer class="lech-bar flex flex-wrap items-center justify-between gap-3 px-5 py-2.5 text-xs">
                {{-- Po lewej autor (link do profilu) i data --}}
                <div class="flex min-w-0 items-center gap-1.5 text-lech-100">
                    @if ($news->author)
                        <a href="{{ route('team.show', $news->author) }}" wire:navigate class="truncate font-semibold text-white hover:underline">{{ $news->author->name }}</a>
                        <span aria-hidden="true">·</span>
                    @endif
                    <time datetime="{{ $news->published_at->toIso8601String() }}">{{ $news->published_at->format('d.m.Y H:i') }}</time>
                </div>

                {{-- Po prawej kciuki; liczby ocen widać dopiero po zagłosowaniu --}}
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
    @empty
        <flux:card>
            <flux:text>{{ __('No news yet.') }}</flux:text>
        </flux:card>
    @endforelse

    @if ($this->items->count() >= $perPage)
        <div class="flex justify-center">
            <flux:button size="sm" variant="ghost" wire:click="more" icon="arrow-down">{{ __('Older news') }}</flux:button>
        </div>
    @endif
</section>
