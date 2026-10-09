<?php

use App\Models\News;
use App\Models\NewsVote;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Newsy na stronie głównej. Zalogowani oceniają kciukiem w górę (zielony) albo w dół (czerwony),
 * ponowny klik w ten sam kciuk cofa ocenę; nieocenione kciuki są białe. Goście widzą tylko liczniki.
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

<section class="space-y-3">
    @if ($this->items->isNotEmpty())
        <flux:heading size="lg">{{ __('News') }}</flux:heading>

        <div class="space-y-3">
            @foreach ($this->items as $news)
                @php
                    $mine = $this->myVotes[$news->id] ?? 0;
                    $base = 'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm font-semibold tabular-nums shadow-xs transition';
                    $white = 'border-zinc-200 bg-white text-zinc-500 dark:border-zinc-600 dark:bg-white dark:text-zinc-600';
                    $upClass = $mine === 1 ? 'border-green-600 bg-green-600 text-white' : $white;
                    $downClass = $mine === -1 ? 'border-red-600 bg-red-600 text-white' : $white;
                    $hover = auth()->check() ? 'cursor-pointer hover:scale-105' : 'cursor-default';
                @endphp
                <article id="news-{{ $news->id }}" wire:key="home-news-{{ $news->id }}"
                    class="scroll-mt-20 space-y-3 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="space-y-1">
                        <h3 class="text-lg font-bold text-lech-800 dark:text-lech-200">{{ $news->title }}</h3>
                        <p class="text-xs text-zinc-500">
                            {{ $news->published_at->format('d.m.Y H:i') }}
                            @if ($news->author)
                                · {{ $news->author->name }}
                            @endif
                        </p>
                    </div>
                    <div class="whitespace-pre-line text-sm text-zinc-700 dark:text-zinc-300">{{ $news->body }}</div>

                    <div class="flex items-center gap-2">
                        @auth
                            <button type="button" wire:click="vote({{ $news->id }}, 1)" class="{{ $base }} {{ $upClass }} {{ $hover }}"
                                aria-label="{{ __('Thumbs up') }}" aria-pressed="{{ $mine === 1 ? 'true' : 'false' }}">
                                <flux:icon.hand-thumb-up :variant="$mine === 1 ? 'solid' : 'outline'" class="size-4" />
                                {{ $news->up_count }}
                            </button>
                            <button type="button" wire:click="vote({{ $news->id }}, -1)" class="{{ $base }} {{ $downClass }} {{ $hover }}"
                                aria-label="{{ __('Thumbs down') }}" aria-pressed="{{ $mine === -1 ? 'true' : 'false' }}">
                                <flux:icon.hand-thumb-down :variant="$mine === -1 ? 'solid' : 'outline'" class="size-4" />
                                {{ $news->down_count }}
                            </button>
                        @else
                            <span class="{{ $base }} {{ $white }} {{ $hover }}" title="{{ __('Log in to rate') }}">
                                <flux:icon.hand-thumb-up class="size-4" /> {{ $news->up_count }}
                            </span>
                            <span class="{{ $base }} {{ $white }} {{ $hover }}" title="{{ __('Log in to rate') }}">
                                <flux:icon.hand-thumb-down class="size-4" /> {{ $news->down_count }}
                            </span>
                            <flux:link :href="route('login')" class="text-xs">{{ __('Log in to rate') }}</flux:link>
                        @endauth
                    </div>
                </article>
            @endforeach
        </div>

        @if ($this->items->count() >= $perPage)
            <div class="flex justify-center">
                <flux:button size="sm" variant="ghost" wire:click="more" icon="arrow-down">{{ __('Older news') }}</flux:button>
            </div>
        @endif
    @endif
</section>
