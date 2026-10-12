<?php

use App\Enums\MatchCompetition;
use App\Enums\MatchdayStatus;
use App\Enums\Permission;
use App\Models\LechMatch;
use App\Support\Audit;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kalendarz meczów Lecha: wstępny terminarz, z którego kolejki sezonu wybierają mecz (panel „Mecze”, edycja kolejki).
 * Zmiana meczu przypisanego do nierozegranej kolejki od razu zmienia kolejkę (LechMatch::syncMatchday):
 * przełożony mecz robi z kolejki przełożoną, a typy graczy zostają do wyboru nowego meczu.
 * Podgląd: season-list, zmiany: season-edit.
 */
new class extends Component {
    // Pokaż także mecze rozegrane i z przeszłości.
    #[Url(as: 'all', except: false)]
    public bool $showAll = false;

    // --- formularz meczu ---
    public ?int $editId = null;
    public string $opponent = '';
    public bool $isHome = true;
    public string $competition = 'league';
    public string $kickoffAt = '';
    public bool $postponed = false;
    public string $note = '';

    public ?int $deleteId = null;

    public function render(): View
    {
        return $this->view()->title(__('Lech calendar'));
    }

    /** Mecze od najbliższego, bez terminu na końcu. Domyślnie bez meczów z przeszłości. */
    #[Computed]
    public function matches()
    {
        return LechMatch::with('matchday.season')
            ->when(!$this->showAll, fn($q) => $q->where(fn($q) => $q->whereNull('kickoff_at')->orWhere('kickoff_at', '>=', now()->subDay())))
            ->orderByRaw('kickoff_at is null')
            ->orderBy('kickoff_at')
            ->orderBy('id')
            ->get();
    }

    public function openForm(?int $id = null): void
    {
        $this->authorizeEdit();
        $this->reset('editId', 'opponent', 'isHome', 'competition', 'kickoffAt', 'postponed', 'note');
        $this->resetValidation();

        if ($id) {
            $match = LechMatch::findOrFail($id);

            if ($this->isPlayed($match)) {
                return;
            }

            $this->editId = $match->id;
            $this->opponent = $match->opponent;
            $this->isHome = $match->is_home;
            $this->competition = MatchCompetition::tryFrom($match->competition)?->value ?? MatchCompetition::DEFAULT->value;
            $this->kickoffAt = $match->kickoff_at?->format('Y-m-d\TH:i') ?? '';
            $this->postponed = $match->postponed;
            $this->note = (string) $match->note;
        }

        Flux::modal('lech-match-form')->show();
    }

    public function save(): void
    {
        $this->authorizeEdit();
        $this->opponent = Str::squish($this->opponent);

        $this->validate(
            [
                'opponent' => ['required', 'string', 'max:80'],
                'isHome' => ['boolean'],
                'competition' => ['required', Rule::enum(MatchCompetition::class)],
                'kickoffAt' => ['nullable', 'date_format:Y-m-d\TH:i'],
                'postponed' => ['boolean'],
                'note' => ['nullable', 'string', 'max:120'],
            ],
            [],
            ['opponent' => __('Opponent'), 'competition' => __('Competition'), 'kickoffAt' => __('Kickoff'), 'note' => __('Note')],
        );

        $match = $this->editId ? LechMatch::findOrFail($this->editId) : new LechMatch();

        if ($match->exists && $this->isPlayed($match)) {
            return;
        }

        $before = $match->exists ? $this->snapshot($match) : [];
        $matchday = $match->matchday()->first();
        $fixtureBefore = $matchday?->isFilled() ? [$matchday->fixture, $matchday->kickoff_at?->format('Y-m-d H:i'), $matchday->status] : null;

        $match
            ->fill([
                'opponent' => $this->opponent,
                'is_home' => $this->isHome,
                'competition' => $this->competition,
                'kickoff_at' => $this->kickoffAt !== '' ? Carbon::createFromFormat('Y-m-d\TH:i', $this->kickoffAt) : null,
                'postponed' => $this->postponed,
                'note' => trim($this->note) ?: null,
            ])
            ->save();

        $warning = $match->syncMatchday();
        Audit::log($before ? 'lech_match.updated' : 'lech_match.created', null, $before, $this->snapshot($match), $match->fixture());

        // Informacje systemowe: zmiana meczu w kolejce (przełożenie, nowy termin).
        if ($matchday && ($matchday = $matchday->fresh()) && $matchday->isFilled()) {
            $after = [$matchday->fixture, $matchday->kickoff_at?->format('Y-m-d H:i'), $matchday->status];

            if ($after !== $fixtureBefore) {
                \App\Support\SystemFeed::record(
                    'matches',
                    $matchday->status === MatchdayStatus::Postponed ? 'Match postponed: :fixture (matchday :number)' : 'Match updated: :fixture (matchday :number, :date)',
                    ['fixture' => $matchday->fixture, 'number' => $matchday->number, 'date' => $matchday->kickoff_at->format('d.m.Y H:i')],
                    'results',
                    ['round' => $matchday->number],
                );
            }
        }

        Flux::modal('lech-match-form')->close();
        unset($this->matches);

        $warning !== null
            ? Flux::toast(text: __('Match saved, but its matchday is postponed: :reason', ['reason' => $warning]), variant: 'warning')
            : Flux::toast(text: __('Match saved.'), variant: 'success');
    }

    public function confirmDelete(int $id): void
    {
        $this->authorizeEdit();
        $match = LechMatch::findOrFail($id);

        if ($match->matchday()->exists()) {
            Flux::toast(text: __('This match is assigned to a matchday. Choose another match for the matchday first.'), variant: 'warning');

            return;
        }

        $this->deleteId = $match->id;
        Flux::modal('delete-lech-match')->show();
    }

    public function delete(): void
    {
        $this->authorizeEdit();
        $match = LechMatch::findOrFail((int) $this->deleteId);

        if (!$match->matchday()->exists()) {
            Audit::log('lech_match.deleted', null, $this->snapshot($match), [], $match->fixture());
            $match->delete();
        }

        $this->deleteId = null;
        Flux::modal('delete-lech-match')->close();
        unset($this->matches);
        Flux::toast(text: __('Match deleted.'), variant: 'success');
    }

    /** Mecz rozegranej kolejki jest tylko do odczytu (wynik i punkty już policzone). */
    private function isPlayed(LechMatch $match): bool
    {
        if ($match->matchday()->where('status', MatchdayStatus::Played->value)->exists()) {
            Flux::toast(text: __('A played matchday cannot be edited.'), variant: 'warning');

            return true;
        }

        return false;
    }

    private function snapshot(LechMatch $match): array
    {
        return [
            'opponent' => $match->opponent,
            'home_away' => $match->is_home ? __('Home') : __('Away'),
            'competition' => $match->competitionLabel(),
            'kickoff_at' => $match->kickoff_at?->format('Y-m-d H:i'),
            'postponed' => $match->postponed ? __('Yes') : __('No'),
        ];
    }

    private function authorizeEdit(): void
    {
        abort_unless(auth()->user()?->can(Permission::SeasonEdit->value), 403);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Lech calendar') }}</flux:heading>
            <flux:subheading>{{ __('Preliminary calendar of Lech matches. A new season adds an unnamed match for each matchday: fill in the opponent and the kickoff here or in Matchdays.') }}</flux:subheading>
        </div>

        <div class="flex items-center gap-3">
            <flux:switch wire:model.live="showAll" :label="__('Show past matches')" />
            @can(\App\Enums\Permission::SeasonEdit->value)
                <flux:button variant="primary" icon="plus" wire:click="openForm">{{ __('New match') }}</flux:button>
            @endcan
        </div>
    </div>

    @if ($this->matches->isEmpty())
        <flux:card>
            <flux:text>{{ __('No matches in the calendar yet.') }}</flux:text>
        </flux:card>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Kickoff') }}</flux:table.column>
                <flux:table.column>{{ __('Match') }}</flux:table.column>
                <flux:table.column>{{ __('Competition') }}</flux:table.column>
                <flux:table.column>{{ __('Matchday') }}</flux:table.column>
                <flux:table.column>{{ __('Note') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->matches as $match)
                    @php
                        $matchday = $match->matchday;
                        $played = $matchday?->status === \App\Enums\MatchdayStatus::Played;
                    @endphp
                    <flux:table.row :key="'lech-match-'.$match->id">
                        <flux:table.cell>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="tabular-nums">{{ $match->kickoff_at?->format('d.m.Y H:i') ?? '—' }}</span>
                                @if ($match->postponed)
                                    <flux:badge size="sm" color="amber">{{ __('Postponed') }}</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell variant="strong">
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($match->isPlaceholder())
                                    {{-- „Mecz bez nazwy” z utworzenia sezonu: czeka na rywala i termin --}}
                                    <flux:badge size="sm" color="amber" icon="pencil-square">{{ $match->fixture() }}</flux:badge>
                                @else
                                    <span>{{ $match->fixture() }}</span>
                                    <flux:badge size="sm" color="zinc">{{ $match->is_home ? __('Home') : __('Away') }}</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="text-zinc-500">{{ $match->competitionLabel() }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($matchday)
                                <flux:badge size="sm" :color="$matchday->status->color()">
                                    {{ $matchday->season?->title }}, {{ __('matchday :n', ['n' => $matchday->number]) }}
                                </flux:badge>
                            @else
                                <span class="text-zinc-400">{{ __('Not in any matchday') }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-sm text-zinc-500">{{ $match->note ?? '' }}</flux:table.cell>
                        <flux:table.cell align="end">
                            @can(\App\Enums\Permission::SeasonEdit->value)
                                @if (!$played)
                                    <flux:button variant="ghost" size="sm" icon="pencil-square" inset="top bottom" wire:click="openForm({{ $match->id }})" :aria-label="__('Edit')" />
                                    @if (!$matchday)
                                        <flux:button variant="ghost" size="sm" icon="trash" inset="top bottom" wire:click="confirmDelete({{ $match->id }})" :aria-label="__('Delete')" />
                                    @endif
                                @endif
                            @endcan
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    {{-- Modal: mecz w kalendarzu --}}
    <flux:modal name="lech-match-form" class="w-full md:w-[30rem]">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editId ? __('Edit match') : __('New match') }}</flux:heading>

            <flux:input wire:model="opponent" :label="__('Opponent')" placeholder="Legia Warszawa" />
            <flux:switch wire:model="isHome" :label="__('Home match')" />
            <flux:select wire:model="competition" :label="__('Competition')">
                @foreach (\App\Enums\MatchCompetition::options() as $value => $label)
                    <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="kickoffAt" type="datetime-local" :label="__('Kickoff')" :description="__('Polish time. Can be empty when the date is not known yet.')" />
            <flux:switch wire:model="postponed" :label="__('Postponed')"
                :description="__('The matchday with this match waits for a new match. The tips of the players stay.')" />
            <flux:input wire:model="note" :label="__('Note')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="delete-lech-match" class="w-full md:w-[28rem]">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Delete this match?') }}</flux:heading>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
