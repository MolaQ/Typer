<?php

use App\Enums\Permission;
use App\Enums\QuestionSide;
use App\Models\Question;
use App\Support\Audit;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Bank pytań bonusowych tak/nie (regulamin, punkt 4).
 * Podgląd: season-list. Dodawanie, edycja, włączanie i usuwanie: season-edit.
 * Pytania użyte w zestawach kolejek nie da się usunąć, można je wyłączyć (nie będą losowane).
 */
new class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $side = '';

    #[Url(except: '')]
    public string $state = '';

    #[Url(except: 'created_at')]
    public string $sortBy = 'created_at';

    #[Url(except: 'desc')]
    public string $sortDirection = 'desc';

    // Formularz
    public ?int $questionId = null;
    public string $text = '';
    public string $formSide = 'offensive';
    public bool $isActive = true;

    // Usuwanie
    public ?int $deleteId = null;
    public string $deleteText = '';

    public function render(): View
    {
        return $this->view()->title(__('Question bank'));
    }

    /* ==================================================================
     | DANE
     * ================================================================*/

    #[Computed]
    public function stats(): array
    {
        $rows = Question::selectRaw('side, is_active, count(*) as total')->groupBy('side', 'is_active')->get();

        $count = fn(string $side, ?bool $active = null) => (int) $rows
            ->where('side', $side)
            ->when($active !== null, fn($c) => $c->where('is_active', $active))
            ->sum('total');

        return [
            'offensive' => $count('offensive'),
            'defensive' => $count('defensive'),
            'offensiveActive' => $count('offensive', true),
            'defensiveActive' => $count('defensive', true),
        ];
    }

    #[Computed]
    public function questions()
    {
        return Question::query()
            ->withCount('assignments')
            ->when($this->search !== '', fn($q) => $q->where('text', 'like', '%' . $this->search . '%'))
            ->when(in_array($this->side, array_column(QuestionSide::cases(), 'value'), true), fn($q) => $q->where('side', $this->side))
            ->when($this->state === 'active', fn($q) => $q->where('is_active', true))
            ->when($this->state === 'inactive', fn($q) => $q->where('is_active', false))
            ->orderBy($this->safeSortBy(), $this->sortDirection === 'asc' ? 'asc' : 'desc')
            ->paginate(15, pageName: 'questionsPage');
    }

    private function safeSortBy(): string
    {
        return in_array($this->sortBy, ['text', 'side', 'created_at'], true) ? $this->sortBy : 'created_at';
    }

    public function sort(string $column): void
    {
        if (!in_array($column, ['text', 'side', 'created_at'], true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage('questionsPage');
    }

    public function updatedSearch(): void
    {
        $this->resetPage('questionsPage');
    }

    public function updatedSide(): void
    {
        $this->resetPage('questionsPage');
    }

    public function updatedState(): void
    {
        $this->resetPage('questionsPage');
    }

    /* ==================================================================
     | FORMULARZ
     * ================================================================*/

    protected function rules(): array
    {
        return [
            'text' => ['required', 'string', 'min:5', 'max:255', Rule::unique('questions', 'text')->ignore($this->questionId)],
            'formSide' => ['required', Rule::in(array_column(QuestionSide::cases(), 'value'))],
            'isActive' => ['boolean'],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['text' => __('Question'), 'formSide' => __('Type')];
    }

    public function create(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $this->resetForm();
        Flux::modal('question-form')->show();
    }

    public function edit(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $question = Question::findOrFail($id);

        $this->questionId = $question->id;
        $this->text = $question->text;
        $this->formSide = $question->side->value;
        $this->isActive = $question->is_active;
        $this->resetValidation();

        Flux::modal('question-form')->show();
    }

    public function save(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $this->text = trim($this->text);
        $this->validate();

        $question = $this->questionId ? Question::findOrFail($this->questionId) : new Question();
        $isNew = !$question->exists;
        $before = $isNew ? [] : ['text' => $question->text, 'side' => $question->side->label(), 'active' => $question->is_active];

        // Zmiana strony pytania użytego w zestawie zepsułaby zestaw (5 ofensywnych + 5 defensywnych).
        if (!$isNew && $question->side->value !== $this->formSide && $question->assignments()->exists()) {
            $this->addError('formSide', __('This question is used in a matchday. You cannot change its type.'));

            return;
        }

        $question->fill(['text' => $this->text, 'side' => $this->formSide, 'is_active' => $this->isActive])->save();

        $after = ['text' => $question->text, 'side' => $question->side->label(), 'active' => $question->is_active];

        Audit::log($isNew ? 'question.created' : 'question.updated', null, $before, $after, $question->text);

        Flux::modal('question-form')->close();
        $this->resetForm();
        unset($this->questions, $this->stats);
        Flux::toast(text: __('Question saved.'), variant: 'success');
    }

    public function resetForm(): void
    {
        $this->reset('questionId', 'text', 'formSide', 'isActive');
        $this->resetValidation();
    }

    /** Włącza lub wyłącza pytanie (wyłączone nie bierze udziału w losowaniu). */
    public function toggle(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $question = Question::findOrFail($id);
        $question->update(['is_active' => !$question->is_active]);

        Audit::log('question.updated', null, ['active' => !$question->is_active], ['active' => $question->is_active], $question->text);

        unset($this->questions, $this->stats);
    }

    /* ==================================================================
     | USUWANIE
     * ================================================================*/

    public function confirmDelete(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $question = Question::withCount('assignments')->findOrFail($id);

        if ($question->assignments_count > 0) {
            Flux::toast(text: __('This question is used in a matchday. Turn it off instead of deleting it.'), variant: 'warning');

            return;
        }

        $this->deleteId = $question->id;
        $this->deleteText = $question->text;

        Flux::modal('delete-question')->show();
    }

    public function delete(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $question = Question::withCount('assignments')->findOrFail((int) $this->deleteId);

        abort_if($question->assignments_count > 0, 403);

        $question->delete();

        Audit::log('question.deleted', null, ['text' => $question->text], [], $question->text);

        Flux::modal('delete-question')->close();
        $this->reset('deleteId', 'deleteText');
        unset($this->questions, $this->stats);
        Flux::toast(text: __('Question deleted.'), variant: 'success');
    }

    private function authorizeAbility(Permission $permission): void
    {
        abort_unless(auth()->user()?->can($permission->value), 403);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Question bank') }}</flux:heading>
            <flux:subheading>
                {{ __('Yes/no bonus questions. Every matchday needs 5 offensive and 5 defensive questions for each competition type, without repeats.') }}
            </flux:subheading>
        </div>

        @can(\App\Enums\Permission::SeasonEdit->value)
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New question') }}</flux:button>
        @endcan
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <flux:card class="flex items-center gap-4">
            <flux:icon.arrow-trending-up class="size-8 text-green-500" />
            <div>
                <flux:text>{{ __('Offensive questions') }}</flux:text>
                <flux:heading size="xl">{{ $this->stats['offensiveActive'] }} <span
                        class="text-sm font-normal text-zinc-500">/ {{ $this->stats['offensive'] }}
                        {{ __('active') }}</span></flux:heading>
            </div>
        </flux:card>
        <flux:card class="flex items-center gap-4">
            <flux:icon.shield-check class="size-8 text-blue-500" />
            <div>
                <flux:text>{{ __('Defensive questions') }}</flux:text>
                <flux:heading size="xl">{{ $this->stats['defensiveActive'] }} <span
                        class="text-sm font-normal text-zinc-500">/ {{ $this->stats['defensive'] }}
                        {{ __('active') }}</span></flux:heading>
            </div>
        </flux:card>
    </div>

    <div class="flex flex-wrap items-center gap-4">
        <div class="w-full sm:w-80">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" clearable
                :placeholder="__('Search questions...')" />
        </div>
        <div class="w-full sm:w-48">
            <flux:select wire:model.live="side">
                <flux:select.option value="">{{ __('All types') }}</flux:select.option>
                @foreach (\App\Enums\QuestionSide::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="w-full sm:w-48">
            <flux:select wire:model.live="state">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
                <flux:select.option value="inactive">{{ __('Turned off') }}</flux:select.option>
            </flux:select>
        </div>
    </div>

    <flux:table :paginate="$this->questions">
        <flux:table.columns>
            <flux:table.column sortable :sorted="$sortBy === 'text'" :direction="$sortDirection"
                wire:click="sort('text')">{{ __('Question') }}</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'side'" :direction="$sortDirection"
                wire:click="sort('side')">{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Used') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column />
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->questions as $question)
                <flux:table.row :key="'question-'.$question->id">
                    <flux:table.cell class="whitespace-normal">{{ $question->text }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$question->side->color()">{{ $question->side->label() }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="tabular-nums text-zinc-500">{{ $question->assignments_count }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($question->is_active)
                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">{{ __('Turned off') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        @can(\App\Enums\Permission::SeasonEdit->value)
                            <flux:dropdown position="bottom" align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" />
                                <flux:menu>
                                    <flux:menu.item icon="pencil-square" wire:click="edit({{ $question->id }})">{{ __('Edit') }}
                                    </flux:menu.item>
                                    <flux:menu.item :icon="$question->is_active ? 'eye-slash' : 'eye'"
                                        wire:click="toggle({{ $question->id }})">
                                        {{ $question->is_active ? __('Turn off') : __('Turn on') }}
                                    </flux:menu.item>
                                    <flux:menu.separator />
                                    <flux:menu.item icon="trash" variant="danger"
                                        wire:click="confirmDelete({{ $question->id }})">{{ __('Delete') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-10 text-center text-zinc-500">
                        {{ __('No questions match your filters. Add them here or run: php artisan db:seed --class=QuestionsSeeder') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    {{-- Modal: formularz pytania --}}
    <flux:modal name="question-form" class="w-full md:w-[34rem]" @close="resetForm">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $questionId ? __('Edit question') : __('New question') }}</flux:heading>

            <flux:textarea wire:model="text" :label="__('Question')" rows="3" maxlength="255"
                :placeholder="__('e.g. Will Lech keep a clean sheet?')" />

            <flux:select wire:model="formSide" :label="__('Type')">
                @foreach (\App\Enums\QuestionSide::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:switch wire:model="isActive" :label="__('Active')"
                :description="__('Turned off questions are not drawn.')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">
                    <span wire:loading.remove wire:target="save">{{ __('Save changes') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Modal: usuwanie pytania --}}
    <flux:modal name="delete-question" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete question?') }}</flux:heading>
                <flux:text class="mt-2">{{ $deleteText }}</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>