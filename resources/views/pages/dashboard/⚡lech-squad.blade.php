<?php

use App\Enums\Permission;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\LechPlayer;
use App\Models\QuestionTemplate;
use App\Support\Audit;
use App\Support\QuestionTemplates;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kadra Lecha (bank pytań 2.0, etap 2): zawodnicy z pozycją, numerem, narodowością, wychowankiem i statusem.
 * Status zmienia się od razu z listy: pytania z szablonów tworzą się i włączają same tylko dla grających regularnie,
 * „wyłączony z losowania” pomija wszystkie pytania zawodnika. Pytania dla pozostałych admin dodaje ręcznie (okno „Pytania”).
 * Zawodnika z pytaniami użytymi w kolejkach nie da się usunąć (ustalenie K3). Podgląd: season-list, zmiany: season-edit.
 */
new class extends Component {
    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'position', except: '')]
    public string $positionFilter = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    /** id zawodnika => status (lista wyboru w tabeli zapisuje się od razu) */
    public array $statuses = [];

    // Formularz zawodnika
    public ?int $playerId = null;
    public string $name = '';
    public string $number = '';
    public string $position = 'midfielder';
    public string $nationality = '';
    public bool $isAcademy = false;
    public string $status = 'regular';

    // Okno „Pytania” (ręczne dodanie pytań z szablonów)
    public ?int $questionsPlayerId = null;
    public array $templateIds = [];

    public ?int $deleteId = null;

    public function mount(): void
    {
        $this->loadStatuses();
    }

    public function render(): View
    {
        return $this->view()->title(__('Lech squad'));
    }

    private function loadStatuses(): void
    {
        $this->statuses = LechPlayer::pluck('status', 'id')->map(fn($s) => $s->value)->all();
    }

    /** Zawodnicy według pozycji (bramkarze pierwsi) i nazwiska, z liczbą pytań aktywnych i wszystkich. */
    #[Computed]
    public function players()
    {
        $order = PlayerPosition::values();

        return LechPlayer::query()
            ->withCount(['questions', 'questions as active_questions_count' => fn($q) => $q->where('is_active', true)])
            ->when(trim($this->search) !== '', fn($q) => $q->where('name', 'like', '%' . trim($this->search) . '%'))
            ->when(PlayerPosition::tryFrom($this->positionFilter), fn($q, $p) => $q->where('position', $p->value))
            ->when(PlayerStatus::tryFrom($this->statusFilter), fn($q, $s) => $q->where('status', $s->value))
            ->get()
            ->sortBy(fn($p) => [array_search($p->position->value, $order, true), $p->number ?? 999, $p->name])
            ->values();
    }

    /** Liczba zawodników w każdym statusie (kafelki nad tabelą). */
    #[Computed]
    public function statusCounts(): array
    {
        return LechPlayer::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();
    }

    #[Computed]
    public function templates()
    {
        return QuestionTemplate::where('is_active', true)->orderBy('id')->get();
    }

    #[Computed]
    public function questionsPlayer(): ?LechPlayer
    {
        return $this->questionsPlayerId ? LechPlayer::with('questions')->find($this->questionsPlayerId) : null;
    }

    /* ==================================================================
     | STATUS Z LISTY
     * ================================================================*/

    public function updatedStatuses(mixed $value, string $key): void
    {
        $this->authorizeEdit();

        $player = LechPlayer::findOrFail((int) $key);
        $status = PlayerStatus::from((string) $value);
        $old = $player->status->label();
        $player->update(['status' => $status]);

        // Zmiana statusu włącza albo wyłącza pytania z automatu (K5).
        QuestionTemplates::sync();
        Audit::log('lech_player.status', null, ['status' => $old], ['status' => $status->label()], $player->name);

        unset($this->players, $this->statusCounts);
        Flux::toast(variant: 'success', text: __('Status of :name: :status.', ['name' => $player->name, 'status' => $status->label()]));
    }

    /* ==================================================================
     | FORMULARZ ZAWODNIKA
     * ================================================================*/

    public function create(): void
    {
        $this->authorizeEdit();
        $this->resetForm();
        Flux::modal('player-form')->show();
    }

    public function edit(int $id): void
    {
        $this->authorizeEdit();
        $player = LechPlayer::findOrFail($id);

        $this->resetValidation();
        $this->playerId = $player->id;
        $this->name = $player->name;
        $this->number = (string) ($player->number ?? '');
        $this->position = $player->position->value;
        $this->nationality = (string) $player->nationality;
        $this->isAcademy = $player->is_academy;
        $this->status = $player->status->value;

        Flux::modal('player-form')->show();
    }

    public function save(): void
    {
        $this->authorizeEdit();

        $this->name = trim($this->name);
        $this->nationality = trim($this->nationality);
        $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:80', Rule::unique('lech_players', 'name')->ignore($this->playerId)],
            'number' => ['nullable', 'integer', 'min:1', 'max:99'],
            'position' => ['required', Rule::enum(PlayerPosition::class)],
            'nationality' => ['nullable', 'string', 'max:40'],
            'status' => ['required', Rule::enum(PlayerStatus::class)],
        ], [], [
            'name' => __('Name'),
            'number' => __('Number'),
            'position' => __('Position'),
            'nationality' => __('Nationality'),
            'status' => __('Status'),
        ]);

        $player = $this->playerId ? LechPlayer::findOrFail($this->playerId) : new LechPlayer();
        $before = $player->exists ? $player->only(['name', 'number', 'nationality']) : [];

        $player->fill([
            'name' => $this->name,
            'number' => $this->number !== '' ? (int) $this->number : null,
            'position' => $this->position,
            'nationality' => $this->nationality !== '' ? $this->nationality : null,
            'is_academy' => $this->isAcademy,
            'status' => $this->status,
        ])->save();

        // Nowe nazwisko, pozycja albo status: pytania z szablonów dostają nową treść i stan.
        $result = QuestionTemplates::sync();
        Audit::log($before === [] ? 'lech_player.created' : 'lech_player.updated', null, $before, $player->only(['name', 'number', 'nationality']), $player->name);

        Flux::modal('player-form')->close();
        $this->resetForm();
        $this->loadStatuses();
        unset($this->players, $this->statusCounts);
        $this->toastResult(__('Player saved.'), $result);
    }

    public function resetForm(): void
    {
        $this->reset('playerId', 'name', 'number', 'position', 'nationality', 'isAcademy', 'status');
        $this->resetValidation();
    }

    /* ==================================================================
     | PYTANIA Z SZABLONÓW (RĘCZNIE)
     * ================================================================*/

    public function openQuestions(int $id): void
    {
        $this->authorizeEdit();
        $player = LechPlayer::findOrFail($id);
        $this->questionsPlayerId = $player->id;
        unset($this->questionsPlayer);

        // Zaznaczone są szablony, z których zawodnik ma już włączone pytanie.
        $this->templateIds = $player->questions()->where('is_active', true)->whereNotNull('question_template_id')
            ->pluck('question_template_id')->map(fn($id) => (string) $id)->all();

        Flux::modal('player-questions')->show();
    }

    public function saveQuestions(): void
    {
        $this->authorizeEdit();
        $player = LechPlayer::findOrFail((int) $this->questionsPlayerId);

        $result = QuestionTemplates::addManual($player, array_map('intval', $this->templateIds));
        Audit::log('lech_player.questions', null, [], ['templates' => count($this->templateIds)], $player->name);

        Flux::modal('player-questions')->close();
        unset($this->players);
        $this->toastResult(__('Questions added.'), $result);
    }

    /* ==================================================================
     | USUWANIE
     * ================================================================*/

    public function confirmDelete(int $id): void
    {
        $this->authorizeEdit();
        $this->deleteId = LechPlayer::findOrFail($id)->id;
        Flux::modal('delete-player')->show();
    }

    public function delete(): void
    {
        $this->authorizeEdit();
        $player = LechPlayer::findOrFail((int) $this->deleteId);

        try {
            QuestionTemplates::deletePlayer($player);
        } catch (DomainException $e) {
            Flux::modal('delete-player')->close();
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Audit::log('lech_player.deleted', null, ['name' => $player->name], [], $player->name);

        Flux::modal('delete-player')->close();
        $this->reset('deleteId');
        $this->loadStatuses();
        unset($this->players, $this->statusCounts);
        Flux::toast(variant: 'success', text: __('Player deleted.'));
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
            <flux:heading size="xl" level="1">{{ __('Lech squad') }}</flux:heading>
            <flux:subheading>
                {{ __('Players for the questions about Lech players. Questions from the templates are created and turned on automatically for players who play regularly.') }}
            </flux:subheading>
        </div>

        @can(\App\Enums\Permission::SeasonEdit->value)
            <div class="flex gap-2">
                <flux:button icon="document-duplicate" :href="route('dashboard.question-templates')" wire:navigate>{{ __('Question templates') }}</flux:button>
                <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New player') }}</flux:button>
            </div>
        @endcan
    </div>

    {{-- Liczba zawodników w statusach: kafelek działa jak filtr --}}
    <div class="flex flex-wrap gap-2">
        @foreach (\App\Enums\PlayerStatus::cases() as $case)
            @php
                $selected = $statusFilter === $case->value;
            @endphp
            <button type="button" wire:click="$set('statusFilter', '{{ $selected ? '' : $case->value }}')"
                class="{{ $selected ? 'border-lech-600 bg-lech-50 text-lech-800 dark:bg-lech-950/50 dark:text-lech-200' : 'border-zinc-200 hover:border-zinc-300 dark:border-zinc-700' }} flex items-center gap-2 rounded-full border px-3 py-1 text-sm">
                <span>{{ $case->label() }}</span>
                <span class="tabular-nums text-zinc-500">{{ $this->statusCounts[$case->value] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-64">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search player...')" />
        </div>
        <flux:select wire:model.live="positionFilter" class="sm:max-w-48">
            <flux:select.option value="">{{ __('All positions') }}</flux:select.option>
            @foreach (\App\Enums\PlayerPosition::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column class="w-12">#</flux:table.column>
            <flux:table.column>{{ __('Player') }}</flux:table.column>
            <flux:table.column>{{ __('Position') }}</flux:table.column>
            <flux:table.column>{{ __('Nationality') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Questions') }}</flux:table.column>
            <flux:table.column />
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->players as $player)
                <flux:table.row :key="$player->id">
                    <flux:table.cell class="tabular-nums text-zinc-500">{{ $player->number ?? '–' }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ $player->name }}</span>
                            @if ($player->is_academy)
                                <flux:badge size="sm" color="blue" icon="academic-cap">{{ __('Academy') }}</flux:badge>
                            @endif
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>{{ $player->position->label() }}</flux:table.cell>
                    <flux:table.cell class="text-zinc-500">{{ $player->nationality ?? '–' }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:select size="sm" wire:model.live="statuses.{{ $player->id }}" class="min-w-44"
                            :disabled="! auth()->user()->can(\App\Enums\Permission::SeasonEdit->value)">
                            @foreach (\App\Enums\PlayerStatus::cases() as $case)
                                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:table.cell>
                    <flux:table.cell class="tabular-nums">
                        <a href="{{ route('dashboard.questions', ['search' => $player->name]) }}" wire:navigate class="hover:underline">
                            {{ $player->active_questions_count }} / {{ $player->questions_count }}
                        </a>
                    </flux:table.cell>
                    <flux:table.cell class="text-right">
                        @can(\App\Enums\Permission::SeasonEdit->value)
                            <div class="flex justify-end gap-1">
                                <flux:button size="xs" variant="ghost" icon="question-mark-circle" wire:click="openQuestions({{ $player->id }})">{{ __('Questions') }}</flux:button>
                                <flux:button size="xs" variant="ghost" icon="pencil" wire:click="edit({{ $player->id }})" :aria-label="__('Edit')" />
                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $player->id }})" :aria-label="__('Delete')" />
                            </div>
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7">
                        <flux:text>{{ __('No players. Add them here or run the squad seeder.') }}</flux:text>
                        <code class="mt-1 block text-xs">php artisan db:seed --class=LechSquadSeeder</code>
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="player-form" class="w-full md:w-[32rem]" @close="resetForm">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $playerId ? __('Edit player') : __('New player') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-[1fr_6rem]">
                <flux:input wire:model="name" :label="__('Name')" maxlength="80" />
                <flux:input wire:model="number" type="number" min="1" max="99" :label="__('Number')" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="position" :label="__('Position')">
                    @foreach (\App\Enums\PlayerPosition::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="nationality" :label="__('Nationality')" maxlength="40" :placeholder="__('e.g. Polska')" />
            </div>

            <flux:select wire:model="status" :label="__('Status')"
                :description="__('Questions from the templates are created automatically only for players who play regularly.')">
                @foreach (\App\Enums\PlayerStatus::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:switch wire:model="isAcademy" :label="__('Lech Academy graduate')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="player-questions" class="w-full md:w-[34rem]">
        <form wire:submit="saveQuestions" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Questions about :name', ['name' => $this->questionsPlayer?->name]) }}</flux:heading>
                <flux:text class="mt-1">{{ __('Add questions from the templates for this player, whatever the status. Questions added here stay on when the status changes.') }}</flux:text>
            </div>

            @if ($this->templates->isEmpty())
                <flux:text>{{ __('No active templates.') }}</flux:text>
            @else
                <flux:checkbox.group wire:model="templateIds">
                    <div class="space-y-2">
                        @foreach ($this->templates as $template)
                            @php
                                $fits = $this->questionsPlayer ? $template->fits($this->questionsPlayer) : true;
                            @endphp
                            <flux:checkbox :value="(string) $template->id"
                                :label="$this->questionsPlayer ? $template->textFor($this->questionsPlayer) : $template->text"
                                :description="$fits ? null : __('Not for this position')" />
                        @endforeach
                    </div>
                </flux:checkbox.group>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Add questions') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="delete-player" class="w-full md:w-[28rem]">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Delete player') }}</flux:heading>
            <flux:text>{{ __('Delete this player and the questions about them? Players whose questions were used in matchdays cannot be deleted: exclude them from the draw instead.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
