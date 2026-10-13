<?php

use App\Enums\MatchWeight;
use App\Enums\Permission;
use App\Enums\QuestionData;
use App\Enums\QuestionMatchType;
use App\Enums\QuestionSide;
use App\Enums\QuestionSubject;
use App\Models\Question;
use App\Models\QuestionCategory;
use App\Support\Audit;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Bank pytań bonusowych tak/nie (regulamin, punkt 4), wersja 2.0:
 * kategoria (w zestawie najwyżej jedno pytanie z kategorii), czego dotyczy, rodzaje meczów Lecha
 * (liga, PP, Europa, sparing), wagi meczów (łatwy, wyrównany, trudny) i wymagane dane (podstawowe albo statystyki).
 * Filtry po każdym kluczu i zbiorcze włączanie/wyłączanie (zaznaczone albo wszystkie z filtra).
 * Podgląd: season-list. Zmiany: season-edit. Pytania użytego w zestawie kolejki nie da się usunąć, można je wyłączyć.
 */
new class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $side = '';

    #[Url(except: '')]
    public string $state = '';

    // Kategoria: id, 'none' (bez kategorii) albo '' (wszystkie)
    #[Url(as: 'category', except: '')]
    public string $categoryFilter = '';

    #[Url(as: 'subject', except: '')]
    public string $subjectFilter = '';

    #[Url(as: 'match', except: '')]
    public string $matchTypeFilter = '';

    #[Url(as: 'weight', except: '')]
    public string $weightFilter = '';

    #[Url(as: 'data', except: '')]
    public string $dataFilter = '';

    #[Url(except: 'created_at')]
    public string $sortBy = 'created_at';

    #[Url(except: 'desc')]
    public string $sortDirection = 'desc';

    // Zaznaczone pytania (akcje zbiorcze)
    public array $checked = [];

    // Formularz pytania
    public ?int $questionId = null;
    public string $text = '';
    public string $formSide = 'offensive';
    public bool $isActive = true;
    public string $formCategory = '';
    public string $formSubject = 'lech';
    public array $formMatchTypes = [];
    public array $formWeights = [];
    public string $formData = 'basic';

    // Kategorie (okno „Kategorie”)
    public ?int $categoryId = null;
    public string $categoryName = '';

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

    /** Kategorie z liczbą aktywnych i wszystkich pytań (kafelki nad tabelą działają jak filtr). */
    #[Computed]
    public function categories()
    {
        return QuestionCategory::query()
            ->withCount(['questions', 'questions as active_count' => fn($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function uncategorized(): int
    {
        return Question::whereNull('category_id')->count();
    }

    /** Zapytanie z bieżącymi filtrami (lista, licznik i akcje zbiorcze „wszystkie z filtra”). */
    private function filtered(): Builder
    {
        return Question::query()
            ->when($this->search !== '', fn($q) => $q->where('text', 'like', '%' . $this->search . '%'))
            ->when(in_array($this->side, array_column(QuestionSide::cases(), 'value'), true), fn($q) => $q->where('side', $this->side))
            ->when($this->state === 'active', fn($q) => $q->where('is_active', true))
            ->when($this->state === 'inactive', fn($q) => $q->where('is_active', false))
            ->when($this->categoryFilter === 'none', fn($q) => $q->whereNull('category_id'))
            ->when(ctype_digit($this->categoryFilter), fn($q) => $q->where('category_id', (int) $this->categoryFilter))
            ->when(QuestionSubject::tryFrom($this->subjectFilter), fn($q, $subject) => $q->where('subject', $subject->value))
            ->when(QuestionMatchType::tryFrom($this->matchTypeFilter), fn($q, $type) => $q->forMatchType($type))
            ->when(MatchWeight::tryFrom($this->weightFilter), fn($q, $weight) => $q->forWeight($weight))
            ->when(QuestionData::tryFrom($this->dataFilter), fn($q, $data) => $q->where('data_level', $data->value));
    }

    #[Computed]
    public function questions()
    {
        return $this->filtered()
            ->with('category')
            ->withCount('assignments')
            ->orderBy($this->safeSortBy(), $this->sortDirection === 'asc' ? 'asc' : 'desc')
            ->paginate(15, pageName: 'questionsPage');
    }

    #[Computed]
    public function filteredCount(): int
    {
        return $this->filtered()->count();
    }

    /** Czy jakikolwiek filtr jest ustawiony (przycisk „Wyczyść filtry”). */
    #[Computed]
    public function hasFilters(): bool
    {
        return collect([$this->search, $this->side, $this->state, $this->categoryFilter, $this->subjectFilter, $this->matchTypeFilter, $this->weightFilter, $this->dataFilter])->contains(fn($v) => $v !== '');
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

    /** Każda zmiana filtra wraca na pierwszą stronę i czyści zaznaczenie. */
    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'side', 'state', 'categoryFilter', 'subjectFilter', 'matchTypeFilter', 'weightFilter', 'dataFilter'], true)) {
            $this->resetPage('questionsPage');
            $this->checked = [];
        }
    }

    public function filterCategory(string $value): void
    {
        $this->categoryFilter = $this->categoryFilter === $value ? '' : $value;
        $this->updated('categoryFilter');
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'side', 'state', 'categoryFilter', 'subjectFilter', 'matchTypeFilter', 'weightFilter', 'dataFilter', 'checked');
        $this->resetPage('questionsPage');
    }

    /* ==================================================================
     | AKCJE ZBIORCZE
     * ================================================================*/

    public function checkAllFiltered(): void
    {
        $this->checked = $this->filtered()->pluck('id')->map(fn($id) => (string) $id)->all();
    }

    public function clearChecked(): void
    {
        $this->checked = [];
    }

    /** Włącza albo wyłącza zaznaczone pytania (np. wszystkie sparingowe albo całą kategorię jednym ruchem). */
    public function setCheckedActive(bool $active): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $ids = array_map('intval', $this->checked);
        $changed = Question::whereIn('id', $ids)->where('is_active', !$active)->update(['is_active' => $active]);

        if ($changed > 0) {
            Audit::log('question.bulk_updated', null, [], ['active' => $active, 'questions' => $changed], __('Question bank'));
        }

        $this->checked = [];
        $this->clearCaches();
        Flux::toast(text: trans_choice(':count question changed.|:count questions changed.', $changed, ['count' => $changed]), variant: 'success');
    }

    /* ==================================================================
     | FORMULARZ PYTANIA
     * ================================================================*/

    protected function rules(): array
    {
        return [
            'text' => ['required', 'string', 'min:5', 'max:255', Rule::unique('questions', 'text')->ignore($this->questionId)],
            'formSide' => ['required', Rule::in(array_column(QuestionSide::cases(), 'value'))],
            'isActive' => ['boolean'],
            'formCategory' => ['nullable', Rule::exists('question_categories', 'id')],
            'formSubject' => ['required', Rule::enum(QuestionSubject::class)],
            'formMatchTypes' => ['required', 'array', 'min:1'],
            'formMatchTypes.*' => [Rule::in(QuestionMatchType::values())],
            'formWeights' => ['required', 'array', 'min:1'],
            'formWeights.*' => [Rule::in(MatchWeight::values())],
            'formData' => ['required', Rule::enum(QuestionData::class)],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'text' => __('Question'),
            'formSide' => __('Type'),
            'formCategory' => __('Category'),
            'formSubject' => __('Concerns'),
            'formMatchTypes' => __('Lech matches'),
            'formWeights' => __('Match weight'),
            'formData' => __('Required data'),
        ];
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
        $this->formCategory = (string) ($question->category_id ?? '');
        $this->formSubject = $question->subject->value;
        $this->formMatchTypes = $question->match_types ?? [];
        $this->formWeights = $question->weights ?? [];
        $this->formData = $question->data_level->value;
        $this->resetValidation();

        Flux::modal('question-form')->show();
    }

    /** Podpowiedź pól z treści nowego pytania (kategoria, dotyczy, dane, rodzaje meczów). */
    public function suggest(): void
    {
        if (trim($this->text) === '') {
            return;
        }

        $guess = Question::guessAttributes($this->text);
        $this->formCategory = (string) ($guess['category_id'] ?? '');
        $this->formSubject = $guess['subject'];
        $this->formData = $guess['data_level'];
        $this->formMatchTypes = $guess['match_types'];
    }

    public function save(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $this->text = trim($this->text);
        $this->validate();

        $question = $this->questionId ? Question::findOrFail($this->questionId) : new Question();
        $isNew = !$question->exists;
        $before = $isNew ? [] : $this->snapshot($question);

        // Zmiana strony pytania użytego w zestawie zepsułaby zestaw (5 ofensywnych + 5 defensywnych).
        if (!$isNew && $question->side->value !== $this->formSide && $question->assignments()->exists()) {
            $this->addError('formSide', __('This question is used in a matchday. You cannot change its type.'));

            return;
        }

        $question
            ->fill([
                'text' => $this->text,
                'side' => $this->formSide,
                'is_active' => $this->isActive,
                'category_id' => $this->formCategory !== '' ? (int) $this->formCategory : null,
                'subject' => $this->formSubject,
                // Kolejność jak w enumach (porządek w bazie i na liście).
                'match_types' => array_values(array_intersect(QuestionMatchType::values(), $this->formMatchTypes)),
                'weights' => array_values(array_intersect(MatchWeight::values(), $this->formWeights)),
                'data_level' => $this->formData,
            ])
            ->save();

        Audit::log($isNew ? 'question.created' : 'question.updated', null, $before, $this->snapshot($question->load('category')), $question->text);

        Flux::modal('question-form')->close();
        $this->resetForm();
        $this->clearCaches();
        Flux::toast(text: __('Question saved.'), variant: 'success');
    }

    /** Nowy formularz: aktywne, Lech, liga + PP + Europa, wszystkie wagi, dane podstawowe. */
    public function resetForm(): void
    {
        $this->reset('questionId', 'text', 'formSide', 'isActive', 'formCategory', 'formSubject', 'formData');
        $this->formMatchTypes = array_values(array_diff(QuestionMatchType::values(), [QuestionMatchType::Friendly->value]));
        $this->formWeights = MatchWeight::values();
        $this->resetValidation();
    }

    /** Włącza lub wyłącza pytanie (wyłączone nie bierze udziału w losowaniu). */
    public function toggle(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $question = Question::findOrFail($id);
        $question->update(['is_active' => !$question->is_active]);

        Audit::log('question.updated', null, ['active' => !$question->is_active], ['active' => $question->is_active], $question->text);

        $this->clearCaches();
    }

    /* ==================================================================
     | KATEGORIE
     * ================================================================*/

    public function openCategories(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);
        $this->reset('categoryId', 'categoryName');
        $this->resetValidation();

        Flux::modal('question-categories')->show();
    }

    public function editCategory(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $category = QuestionCategory::findOrFail($id);
        $this->categoryId = $category->id;
        $this->categoryName = $category->name;
    }

    public function saveCategory(): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $this->categoryName = Str::squish($this->categoryName);
        $this->validate(
            ['categoryName' => ['required', 'string', 'min:2', 'max:60', Rule::unique('question_categories', 'name')->ignore($this->categoryId)]],
            [],
            ['categoryName' => __('Category')],
        );

        $category = $this->categoryId ? QuestionCategory::findOrFail($this->categoryId) : new QuestionCategory(['sort_order' => (int) QuestionCategory::max('sort_order') + 1]);
        $before = $category->exists ? ['name' => $category->name] : [];
        $category->fill(['name' => $this->categoryName])->save();

        Audit::log($before ? 'question_category.updated' : 'question_category.created', null, $before, ['name' => $category->name], $category->name);

        $this->reset('categoryId', 'categoryName');
        unset($this->categories);
    }

    /** Usunięcie kategorii zostawia jej pytania bez kategorii (nie znikają z banku). */
    public function deleteCategory(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $category = QuestionCategory::findOrFail($id);
        Audit::log('question_category.deleted', null, ['name' => $category->name], [], $category->name);
        $category->delete();

        if ($this->categoryFilter === (string) $id) {
            $this->categoryFilter = '';
        }

        $this->reset('categoryId', 'categoryName');
        $this->clearCaches();
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
        $this->clearCaches();
        Flux::toast(text: __('Question deleted.'), variant: 'success');
    }

    /* ==================================================================
     | POMOCNICZE
     * ================================================================*/

    /** Pola zapisywane w dzienniku (do porównania "było -> jest"). */
    private function snapshot(Question $question): array
    {
        return [
            'text' => $question->text,
            'side' => $question->side->label(),
            'active' => $question->is_active,
            'category' => $question->category?->name,
            'subject' => $question->subject->label(),
            'match_types' => implode(', ', array_map(fn($t) => $t->label(), $question->matchTypeCases())),
            'weights' => implode(', ', array_map(fn($w) => $w->label(), $question->weightCases())),
            'data' => $question->data_level->label(),
        ];
    }

    private function clearCaches(): void
    {
        unset($this->questions, $this->stats, $this->categories, $this->uncategorized, $this->filteredCount);
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
            <div class="flex gap-2">
                <flux:button icon="tag" wire:click="openCategories">{{ __('Categories') }}</flux:button>
                <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New question') }}</flux:button>
            </div>
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

    {{-- Kategorie jako szybki filtr: aktywne / wszystkie pytania w kategorii --}}
    <div class="flex flex-wrap gap-2">
        @foreach ($this->categories as $category)
            @php
                $selected = $categoryFilter === (string) $category->id;
            @endphp
            <button type="button" wire:click="filterCategory('{{ $category->id }}')"
                class="{{ $selected ? 'border-lech-600 bg-lech-50 text-lech-800 dark:bg-lech-950/50 dark:text-lech-200' : 'border-zinc-200 hover:border-zinc-300 dark:border-zinc-700' }} flex items-center gap-2 rounded-full border px-3 py-1 text-sm">
                <span>{{ $category->name }}</span>
                <span class="tabular-nums text-zinc-500">{{ $category->active_count }}/{{ $category->questions_count }}</span>
            </button>
        @endforeach
        @if ($this->uncategorized > 0)
            <button type="button" wire:click="filterCategory('none')"
                class="{{ $categoryFilter === 'none' ? 'border-amber-500 bg-amber-50 dark:bg-amber-500/10' : 'border-dashed border-amber-400' }} flex items-center gap-2 rounded-full border px-3 py-1 text-sm">
                <span>{{ __('No category') }}</span>
                <span class="tabular-nums text-zinc-500">{{ $this->uncategorized }}</span>
            </button>
        @endif
    </div>

    {{-- Filtry --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" clearable :placeholder="__('Search questions...')" />
        <flux:select wire:model.live="state">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
            <flux:select.option value="inactive">{{ __('Turned off') }}</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="subjectFilter">
            <flux:select.option value="">{{ __('Concerns: all') }}</flux:select.option>
            @foreach (\App\Enums\QuestionSubject::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="matchTypeFilter">
            <flux:select.option value="">{{ __('All Lech matches') }}</flux:select.option>
            @foreach (\App\Enums\QuestionMatchType::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="weightFilter">
            <flux:select.option value="">{{ __('All match weights') }}</flux:select.option>
            @foreach (\App\Enums\MatchWeight::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="dataFilter">
            <flux:select.option value="">{{ __('All data levels') }}</flux:select.option>
            @foreach (\App\Enums\QuestionData::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="side">
            <flux:select.option value="">{{ __('All types') }}</flux:select.option>
            @foreach (\App\Enums\QuestionSide::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        @if ($this->hasFilters)
            <flux:button variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
        @endif
    </div>

    {{-- Akcje zbiorcze: włącz/wyłącz zaznaczone (np. wszystkie z filtra: kategoria, sparing, zawodnik) --}}
    @can(\App\Enums\Permission::SeasonEdit->value)
        @php
            $checkedCount = count($checked);
        @endphp
        <div class="flex flex-wrap items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
            <flux:text class="me-2">{{ __('Selected: :count', ['count' => $checkedCount]) }}</flux:text>
            <flux:button size="sm" variant="ghost" icon="check" wire:click="checkAllFiltered">
                {{ __('Select all matching (:count)', ['count' => $this->filteredCount]) }}
            </flux:button>
            @if ($checkedCount > 0)
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearChecked">{{ __('Clear selection') }}</flux:button>
                <flux:button size="sm" icon="eye" wire:click="setCheckedActive(true)">{{ __('Turn on') }}</flux:button>
                <flux:button size="sm" icon="eye-slash" wire:click="setCheckedActive(false)">{{ __('Turn off') }}</flux:button>
            @endif
        </div>
    @endcan

    <flux:table :paginate="$this->questions">
        <flux:table.columns>
            <flux:table.column class="w-8" />
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
                    <flux:table.cell>
                        <input type="checkbox" wire:model.live="checked" value="{{ $question->id }}" aria-label="{{ __('Select') }}"
                            class="size-4 rounded border-zinc-300 text-lech-700 dark:border-zinc-600">
                    </flux:table.cell>
                    <flux:table.cell class="whitespace-normal">
                        <div>{{ $question->text }}</div>
                        {{-- Klucze pytania: kategoria, dotyczy, mecze Lecha, wagi, dane --}}
                        <div class="mt-1 flex flex-wrap gap-1">
                            @if ($question->category)
                                <flux:badge size="sm" color="indigo" icon="tag">{{ $question->category->name }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="amber" icon="tag">{{ __('No category') }}</flux:badge>
                            @endif
                            <flux:badge size="sm" color="zinc">{{ $question->subject->label() }}</flux:badge>
                            @foreach ($question->matchTypeCases() as $type)
                                <flux:badge size="sm" :color="$type === \App\Enums\QuestionMatchType::Friendly ? 'purple' : 'sky'">{{ $type->label() }}</flux:badge>
                            @endforeach
                            @if (count($question->weightCases()) < count(\App\Enums\MatchWeight::cases()))
                                @foreach ($question->weightCases() as $weight)
                                    <flux:badge size="sm" :color="$weight->color()">{{ $weight->label() }}</flux:badge>
                                @endforeach
                            @endif
                            @if ($question->data_level === \App\Enums\QuestionData::Stats)
                                <flux:badge size="sm" color="orange" icon="chart-bar">{{ $question->data_level->label() }}</flux:badge>
                            @endif
                        </div>
                    </flux:table.cell>
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
                    <flux:table.cell colspan="6" class="py-10 text-center text-zinc-500">
                        {{ __('No questions match your filters. Add them here or run: php artisan db:seed --class=QuestionsSeeder') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    {{-- Modal: formularz pytania --}}
    <flux:modal name="question-form" class="w-full md:w-[40rem]" @close="resetForm">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $questionId ? __('Edit question') : __('New question') }}</flux:heading>

            <div class="space-y-2">
                <flux:textarea wire:model="text" :label="__('Question')" rows="3" maxlength="255"
                    :placeholder="__('e.g. Will Lech keep a clean sheet?')" />
                <flux:button size="sm" variant="ghost" icon="sparkles" wire:click="suggest">{{ __('Suggest the fields from the text') }}</flux:button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="formCategory" :label="__('Category')">
                    <flux:select.option value="">{{ __('No category') }}</flux:select.option>
                    @foreach ($this->categories as $category)
                        <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="formSubject" :label="__('Concerns')">
                    @foreach (\App\Enums\QuestionSubject::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:checkbox.group wire:model="formMatchTypes" :label="__('Lech matches')"
                :description="__('A friendly draws only from questions marked as friendly.')">
                <div class="flex flex-wrap gap-x-6 gap-y-2">
                    @foreach (\App\Enums\QuestionMatchType::cases() as $case)
                        <flux:checkbox :value="$case->value" :label="$case->label()" />
                    @endforeach
                </div>
            </flux:checkbox.group>

            <flux:checkbox.group wire:model="formWeights" :label="__('Match weight')"
                :description="__('Estimated by the admin for each Lech match.')">
                <div class="flex flex-wrap gap-x-6 gap-y-2">
                    @foreach (\App\Enums\MatchWeight::cases() as $case)
                        <flux:checkbox :value="$case->value" :label="$case->label()" />
                    @endforeach
                </div>
            </flux:checkbox.group>

            <flux:radio.group wire:model="formData" :label="__('Required data')" variant="segmented">
                @foreach (\App\Enums\QuestionData::cases() as $case)
                    <flux:radio :value="$case->value" :label="$case->label()" />
                @endforeach
            </flux:radio.group>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="formSide" :label="__('Type')">
                    @foreach (\App\Enums\QuestionSide::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:switch wire:model="isActive" :label="__('Active')"
                    :description="__('Turned off questions are not drawn.')" />
            </div>

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

    {{-- Modal: kategorie pytań --}}
    <flux:modal name="question-categories" class="w-full md:w-[32rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Question categories') }}</flux:heading>
                <flux:text class="mt-1">{{ __('A matchday set has at most one question from each category.') }}</flux:text>
            </div>

            <ul class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @foreach ($this->categories as $category)
                    <li class="flex items-center justify-between gap-3 py-2" wire:key="category-{{ $category->id }}">
                        <div>
                            <span class="font-medium">{{ $category->name }}</span>
                            <span class="text-sm tabular-nums text-zinc-500">· {{ $category->active_count }}/{{ $category->questions_count }}</span>
                        </div>
                        <div class="flex gap-1">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editCategory({{ $category->id }})" :aria-label="__('Edit')" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteCategory({{ $category->id }})"
                                wire:confirm="{{ __('Delete this category? Its questions stay in the bank without a category.') }}" :aria-label="__('Delete')" />
                        </div>
                    </li>
                @endforeach
            </ul>

            <form wire:submit="saveCategory" class="flex items-end gap-2">
                <div class="flex-1">
                    <flux:input wire:model="categoryName" :label="$categoryId ? __('Rename category') : __('New category')" />
                </div>
                <flux:button type="submit" variant="primary">{{ $categoryId ? __('Save') : __('Add') }}</flux:button>
            </form>
        </div>
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