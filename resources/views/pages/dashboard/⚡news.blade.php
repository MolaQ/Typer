<?php

use App\Enums\Permission;
use App\Models\News;
use App\Support\Audit;
use App\Support\SystemFeed;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Newsy na stronę główną: lista, dodawanie, edycja, publikacja i usuwanie (uprawnienie news-create).
 * Niepublikowany news to szkic. Pierwsza publikacja trafia też do informacji systemowych.
 */
new class extends Component {
    use WithPagination;

    public ?int $editId = null;
    public string $title = '';
    public string $body = '';
    public bool $publish = true;

    public ?int $deleteId = null;

    public function render(): View
    {
        return $this->view()->title(__('News'));
    }

    #[Computed]
    public function items()
    {
        return News::with('author')
            ->withCount([
                'votes as up_count' => fn ($q) => $q->where('value', 1),
                'votes as down_count' => fn ($q) => $q->where('value', -1),
            ])
            ->orderByRaw('published_at is not null')
            ->latest('published_at')
            ->latest('id')
            ->paginate(20);
    }

    public function openForm(?int $id = null): void
    {
        $this->authorize(Permission::NewsCreate->value);
        $this->reset('editId', 'title', 'body', 'publish');
        $this->resetValidation();

        if ($id) {
            $news = News::findOrFail($id);
            $this->editId = $news->id;
            $this->title = $news->title;
            $this->body = $news->body;
            $this->publish = $news->published_at !== null;
        }

        Flux::modal('news-form')->show();
    }

    public function save(): void
    {
        $this->authorize(Permission::NewsCreate->value);

        $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
        ], [], ['title' => __('Title'), 'body' => __('Content')]);

        $news = $this->editId ? News::findOrFail($this->editId) : new News(['user_id' => auth()->id()]);
        $old = $news->only(['title', 'published_at']);
        $firstPublish = $this->publish && $news->published_at === null;

        $news->fill([
            'title' => trim($this->title),
            'body' => trim($this->body),
            // Data publikacji zostaje przy edycji opublikowanego newsa.
            'published_at' => $this->publish ? ($news->published_at ?? now()) : null,
        ])->save();

        Audit::log($this->editId ? 'news.updated' : 'news.created', null, $old, $news->only(['title', 'published_at']), $news->title);

        if ($firstPublish) {
            SystemFeed::record('news', 'News: :title', ['title' => $news->title], 'home', [], auth()->id());
        }

        Flux::modal('news-form')->close();
        $this->reset('editId', 'title', 'body', 'publish');
        unset($this->items);
        Flux::toast(variant: 'success', text: __('News saved.'));
    }

    public function confirmDelete(int $id): void
    {
        $this->authorize(Permission::NewsCreate->value);
        $this->deleteId = News::findOrFail($id)->id;

        Flux::modal('delete-news')->show();
    }

    public function delete(): void
    {
        $this->authorize(Permission::NewsCreate->value);
        $news = News::findOrFail((int) $this->deleteId);
        $news->delete(); // oceny usuwa klucz obcy (cascade)

        Audit::log('news.deleted', null, ['title' => $news->title], [], $news->title);

        Flux::modal('delete-news')->close();
        $this->reset('deleteId');
        unset($this->items);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('News') }}</flux:heading>
            <flux:subheading>{{ __('Announcements on the home page. Logged-in players can rate them.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="openForm">{{ __('New news') }}</flux:button>
    </div>

    <div class="space-y-3">
        @forelse ($this->items as $news)
            <flux:card wire:key="news-{{ $news->id }}" class="space-y-2">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading>{{ $news->title }}</flux:heading>
                            @if ($news->published_at)
                                <flux:badge size="sm" color="green">{{ __('Published') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ __('Draft') }}</flux:badge>
                            @endif
                        </div>
                        <flux:text size="sm">
                            {{ $news->author?->name ?? '–' }}
                            · {{ ($news->published_at ?? $news->created_at)?->format('d.m.Y H:i') }}
                            · <span class="text-green-600">👍 {{ $news->up_count }}</span>
                            · <span class="text-red-600">👎 {{ $news->down_count }}</span>
                        </flux:text>
                    </div>
                    <div class="flex gap-1">
                        <flux:button size="xs" variant="ghost" icon="pencil" wire:click="openForm({{ $news->id }})" :aria-label="__('Edit')" />
                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $news->id }})" :aria-label="__('Delete')" />
                    </div>
                </div>
                <flux:text class="line-clamp-3 whitespace-pre-line">{{ $news->body }}</flux:text>
            </flux:card>
        @empty
            <flux:card>
                <flux:text>{{ __('No news yet.') }}</flux:text>
            </flux:card>
        @endforelse

        {{ $this->items->links() }}
    </div>

    <flux:modal name="news-form" class="w-full max-w-2xl">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editId ? __('Edit news') : __('New news') }}</flux:heading>
            <flux:input wire:model="title" :label="__('Title')" maxlength="150" required />
            <flux:textarea wire:model="body" :label="__('Content')" rows="8" required
                :description="__('Plain text, empty lines separate paragraphs.')" />
            <flux:switch wire:model="publish" :label="__('Publish on the home page')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="delete-news" class="w-full max-w-md">
        <div class="space-y-5">
            <flux:heading size="lg">{{ __('Delete this news?') }}</flux:heading>
            <flux:text>{{ __('Its ratings will be deleted too.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
