<?php

use App\Enums\MatchWeight;
use App\Enums\Permission;
use App\Enums\PlayerPosition;
use App\Enums\QuestionData;
use App\Enums\QuestionMatchType;
use App\Models\QuestionCategory;
use App\Models\QuestionTemplate;
use App\Support\Audit;
use App\Support\QuestionClassifier;
use App\Support\QuestionTemplates;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Szablony pytań o zawodników Lecha (bank pytań 2.0, etap 2), np. „Czy :zawodnik dostanie żółtą kartkę?”.
 * Z szablonu powstaje pytanie w banku dla każdego pasującego zawodnika (pozycje) grającego regularnie; dla pozostałych
 * admin dodaje pytania ręcznie w kadrze. Zmiana szablonu poprawia treść i ustawienia wszystkich jego pytań (K7),
 * wyłączenie szablonu wyłącza jego pytania. Podgląd: season-list, zmiany: season-edit.
 */
new class extends Component {
    // Formularz szablonu
    public ?int $templateId = null;
    public string $text = '';
    public string $formCategory = '';
    public array $formPositions = [];
    public array $formMatchTypes = [];
    public array $formWeights = [];
    public string $formData = 'basic';
    public bool $isActive = true;

    public ?int $deleteId = null;

    public function render(): View
    {
        return $this->view()->title(__('Question templates'));
    }

    #[Computed]
    public function templates()
    {
        return QuestionTemplate::query()
            ->with('category')
            ->withCount(['questions', 'questions as active_questions_count' => fn($q) => $q->where('is_active', true)])
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function categories()
    {
        return QuestionCategory::orderBy('sort_order')->orderBy('name')->get();
    }

    /* ==================================================================
     | FORMULARZ
     * ================================================================*/

    public function create(): void
    {
        $this->authorizeEdit();
        $this->resetForm();
        Flux::modal('template-form')->show();
    }

    public function edit(int $id): void
    {
        $this->authorizeEdit();
        $template = QuestionTemplate::findOrFail($id);

        $this->resetValidation();
        $this->templateId = $template->id;
        $this->text = $template->text;
        $this->formCategory = (string) ($template->category_id ?? '');
        $this->formPositions = $template->positions ?? [];
        $this->formMatchTypes = $template->match_types ?? [];
        $this->formWeights = $template->weights ?? [];
        $this->formData = $template->data_level->value;
        $this->isActive = $template->is_active;

        Flux::modal('template-form')->show();
    }

    public function save(): void
    {
        $this->authorizeEdit();

        $this->text = trim($this->text);
        $this->validate([
            'text' => ['required', 'string', 'min:10', 'max:200', Rule::unique('question_templates', 'text')->ignore($this->templateId),
                // Szablon bez znacznika dałby to samo pytanie dla każdego zawodnika.
                fn($attribute, $value, $fail) => str_contains($value, QuestionTemplate::PLACEHOLDER) ? null : $fail(__('The text must contain :placeholder.', ['placeholder' => QuestionTemplate::PLACEHOLDER]))],
            'formCategory' => ['nullable', Rule::exists('question_categories', 'id')],
            'formPositions.*' => [Rule::in(PlayerPosition::values())],
            'formMatchTypes' => ['required', 'array', 'min:1'],
            'formMatchTypes.*' => [Rule::in(QuestionMatchType::values())],
            'formWeights' => ['required', 'array', 'min:1'],
            'formWeights.*' => [Rule::in(MatchWeight::values())],
            'formData' => ['required', Rule::enum(QuestionData::class)],
        ], [], [
            'text' => __('Template'),
            'formCategory' => __('Category'),
            'formMatchTypes' => __('Lech matches'),
            'formWeights' => __('Match weight'),
            'formData' => __('Required data'),
        ]);

        $template = $this->templateId ? QuestionTemplate::findOrFail($this->templateId) : new QuestionTemplate();
        $before = $template->exists ? ['text' => $template->text, 'active' => $template->is_active] : [];

        $template->fill([
            'text' => $this->text,
            'category_id' => $this->formCategory !== '' ? (int) $this->formCategory : null,
            // Kolejność jak w enumach; wszystkie pozycje zapisujemy jako pustą listę (= każda pozycja).
            'positions' => count($this->formPositions) === count(PlayerPosition::cases()) ? [] : array_values(array_intersect(PlayerPosition::values(), $this->formPositions)),
            'match_types' => array_values(array_intersect(QuestionMatchType::values(), $this->formMatchTypes)),
            'weights' => array_values(array_intersect(MatchWeight::values(), $this->formWeights)),
            'data_level' => $this->formData,
            'is_active' => $this->isActive,
        ])->save();

        $result = QuestionTemplates::sync();
        Audit::log($before === [] ? 'question_template.created' : 'question_template.updated', null, $before, ['text' => $template->text, 'active' => $template->is_active], $template->text);

        Flux::modal('template-form')->close();
        $this->resetForm();
        unset($this->templates);
        $this->toastResult(__('Template saved.'), $result);
    }

    /** Wybór „Wymagane dane” podpowiada rodzaje meczów (statystyki = bez sparingu), jak w banku pytań. */
    public function updatedFormData(): void
    {
        $this->formMatchTypes = QuestionClassifier::matchTypes(QuestionData::from($this->formData));
    }

    public function resetForm(): void
    {
        $this->reset('templateId', 'text', 'formCategory', 'formPositions', 'formData', 'isActive');
        $this->formMatchTypes = QuestionClassifier::matchTypes(QuestionData::Basic);
        $this->formWeights = MatchWeight::values();
        $this->resetValidation();
    }

    /** Przelicza pytania wszystkich szablonów (np. po imporcie kadry). */
    public function syncAll(): void
    {
        $this->authorizeEdit();
        $result = QuestionTemplates::sync();
        unset($this->templates);
        $this->toastResult(__('Questions recalculated.'), $result);
    }

    /* ==================================================================
     | USUWANIE
     * ================================================================*/

    public function confirmDelete(int $id): void
    {
        $this->authorizeEdit();
        $this->deleteId = QuestionTemplate::findOrFail($id)->id;
        Flux::modal('delete-template')->show();
    }

    public function delete(): void
    {
        $this->authorizeEdit();
        $template = QuestionTemplate::findOrFail((int) $this->deleteId);

        QuestionTemplates::deleteTemplate($template);
        Audit::log('question_template.deleted', null, ['text' => $template->text], [], $template->text);

        Flux::modal('delete-template')->close();
        $this->reset('deleteId');
        unset($this->templates);
        Flux::toast(variant: 'success', text: __('Template deleted.'));
    }

    /** @param array{created: int, updated: int, skipped: array<int, string>} $result */
    private function toastResult(string $message, array $result): void
    {
        $text = $message . ' ' . __('Questions: :created new, :updated changed.', ['created' => $result['created'], 'updated' => $result['updated']]);

        if ($result['skipped'] !== []) {
            $text .= ' ' . __('Skipped (the bank already has this text): :list', ['list' => implode('; ', $result['skipped'])]);
        }

        Flux::toast(variant: $result['skipped'] === [] ? 'success' : 'warning', text: $text);
    }

    private function authorizeEdit(): void
    {
        abort_unless(auth()->user()?->can(Permission::SeasonEdit->value), 403);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Question templates') }}</flux:heading>
            <flux:subheading>
                {{ __('Questions about Lech players. :placeholder is replaced with the player name and each template becomes a question in the bank for every matching player who plays regularly.', ['placeholder' => \App\Models\QuestionTemplate::PLACEHOLDER]) }}
            </flux:subheading>
        </div>

        @can(\App\Enums\Permission::SeasonEdit->value)
            <div class="flex gap-2">
                <flux:button icon="users" :href="route('dashboard.lech-squad')" wire:navigate>{{ __('Lech squad') }}</flux:button>
                <flux:button icon="arrow-path" wire:click="syncAll">{{ __('Recalculate questions') }}</flux:button>
                <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New template') }}</flux:button>
            </div>
        @endcan
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Template') }}</flux:table.column>
            <flux:table.column>{{ __('Positions') }}</flux:table.column>
            <flux:table.column>{{ __('Questions') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column />
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->templates as $template)
                <flux:table.row :key="$template->id">
                    <flux:table.cell class="whitespace-normal">
                        <div class="font-medium">{{ $template->text }}</div>
                        <div class="mt-1 flex flex-wrap gap-1">
                            @if ($template->category)
                                <flux:badge size="sm" color="zinc">{{ $template->category->name }}</flux:badge>
                            @endif
                            @if ($template->data_level === \App\Enums\QuestionData::Stats)
                                <flux:badge size="sm" color="orange" icon="chart-bar">{{ $template->data_level->label() }}</flux:badge>
                            @endif
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($template->positionCases() === [])
                            <span class="text-zinc-500">{{ __('All') }}</span>
                        @else
                            <div class="flex flex-wrap gap-1">
                                @foreach ($template->positionCases() as $position)
                                    <flux:badge size="sm">{{ $position->label() }}</flux:badge>
                                @endforeach
                            </div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="tabular-nums">{{ $template->active_questions_count }} / {{ $template->questions_count }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($template->is_active)
                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">{{ __('Turned off') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-right">
                        @can(\App\Enums\Permission::SeasonEdit->value)
                            <div class="flex justify-end gap-1">
                                <flux:button size="xs" variant="ghost" icon="pencil" wire:click="edit({{ $template->id }})" :aria-label="__('Edit')" />
                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $template->id }})" :aria-label="__('Delete')" />
                            </div>
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5">
                        <flux:text>{{ __('No templates yet.') }}</flux:text>
                        <code class="mt-1 block text-xs">php artisan db:seed --class=QuestionTemplatesSeeder</code>
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="template-form" class="w-full md:w-[40rem]" @close="resetForm">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $templateId ? __('Edit template') : __('New template') }}</flux:heading>

            <flux:textarea wire:model="text" :label="__('Template')" rows="2" maxlength="200"
                :placeholder="__('e.g. Will :placeholder get a yellow card?', ['placeholder' => \App\Models\QuestionTemplate::PLACEHOLDER])"
                :description="__('Changing the text also changes the questions already created from this template.')" />

            <flux:select wire:model="formCategory" :label="__('Category')">
                <flux:select.option value="">{{ __('No category') }}</flux:select.option>
                @foreach ($this->categories as $category)
                    <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:checkbox.group wire:model="formPositions" :label="__('Positions')" :description="__('None selected = all positions.')">
                <div class="flex flex-wrap gap-x-6 gap-y-2">
                    @foreach (\App\Enums\PlayerPosition::cases() as $case)
                        <flux:checkbox :value="$case->value" :label="$case->label()" />
                    @endforeach
                </div>
            </flux:checkbox.group>

            <flux:radio.group wire:model.live="formData" :label="__('Required data')" variant="segmented">
                @foreach (\App\Enums\QuestionData::cases() as $case)
                    <flux:radio :value="$case->value" :label="$case->label()" />
                @endforeach
            </flux:radio.group>

            <flux:checkbox.group wire:model="formMatchTypes" :label="__('Lech matches')">
                <div class="flex flex-wrap gap-x-6 gap-y-2">
                    @foreach (\App\Enums\QuestionMatchType::cases() as $case)
                        <flux:checkbox :value="$case->value" :label="$case->label()" />
                    @endforeach
                </div>
            </flux:checkbox.group>

            <flux:checkbox.group wire:model="formWeights" :label="__('Match weight')">
                <div class="flex flex-wrap gap-x-6 gap-y-2">
                    @foreach (\App\Enums\MatchWeight::cases() as $case)
                        <flux:checkbox :value="$case->value" :label="$case->label()" />
                    @endforeach
                </div>
            </flux:checkbox.group>

            <flux:switch wire:model="isActive" :label="__('Active')" :description="__('A turned off template turns off all its questions.')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="delete-template" class="w-full md:w-[28rem]">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Delete template') }}</flux:heading>
            <flux:text>{{ __('Delete this template and its unused questions? Questions already used in matchdays stay in the bank, turned off.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
