<?php

use App\Models\News;
use App\Models\NewsVote;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Pełna treść newsa z ocenami (link z tytułu karty na stronie głównej). */
new #[Layout('layouts::public')] class extends Component {
    public int $newsId;

    public function mount(News $news): void
    {
        abort_unless($news->published_at && $news->published_at->isPast(), 404);
        $this->newsId = $news->id;
    }

    #[Computed]
    public function news(): News
    {
        return News::published()->with('author')->withVoteCounts()->findOrFail($this->newsId);
    }

    #[Computed]
    public function mine(): int
    {
        return auth()->check()
            ? (int) NewsVote::where('news_id', $this->newsId)->where('user_id', auth()->id())->value('value')
            : 0;
    }

    public function vote(int $newsId, int $value): void
    {
        abort_unless(auth()->check(), 403);
        abort_unless(in_array($value, [1, -1], true), 422);

        $this->news->toggleVote(auth()->id(), $value);
        unset($this->news, $this->mine);
    }

    public function render(): View
    {
        return $this->view()->title($this->news->title);
    }
}; ?>

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <div>
        <a href="{{ route('home') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-semibold text-lech-700 hover:underline dark:text-lech-300">
            <flux:icon.arrow-left variant="micro" /> {{ __('All news') }}
        </a>
    </div>

    @include('partials.news-card', ['news' => $this->news, 'mine' => $this->mine, 'full' => true])
</div>
