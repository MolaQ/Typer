<?php

use App\Models\News;
use App\Models\NewsVote;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Newsy na stronie głównej: siatka 3 kolumny x 5 wierszy z paginacją, karty z partials/news-card.
 * Zalogowani oceniają kciukiem w górę (zielony) albo w dół (czerwony), nieocenione kciuki są białe.
 */
new class extends Component {
    use WithPagination;

    /** 3 kolumny x 5 wierszy. */
    private const PER_PAGE = 15;

    #[Computed]
    public function items()
    {
        if (!Schema::hasTable('news')) {
            return null;
        }

        return News::published()
            ->with('author')
            ->withVoteCounts()
            ->latest('published_at')
            ->latest('id')
            ->paginate(self::PER_PAGE, pageName: 'news');
    }

    /** news_id => 1 / -1 dla zalogowanego */
    #[Computed]
    public function myVotes(): array
    {
        if (!auth()->check() || !$this->items || $this->items->isEmpty()) {
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

        News::published()->findOrFail($newsId)->toggleVote(auth()->id(), $value);

        unset($this->items, $this->myVotes);
    }

    public function render(): View
    {
        return $this->view();
    }
}; ?>

<section class="space-y-4">
    <flux:heading size="lg">{{ __('News') }}</flux:heading>

    @if (!$this->items || $this->items->isEmpty())
        <flux:card>
            <flux:text>{{ __('No news yet.') }}</flux:text>
        </flux:card>
    @else
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->items as $news)
                @include('partials.news-card', ['news' => $news, 'mine' => (int) ($this->myVotes[$news->id] ?? 0)])
            @endforeach
        </div>

        {{ $this->items->links() }}
    @endif
</section>
