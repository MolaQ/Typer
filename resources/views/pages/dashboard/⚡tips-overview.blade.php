<?php

use App\Models\Matchday;
use App\Models\MatchdayQuestion;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\Tip;
use App\Models\TipAnswer;
use App\Support\Players;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Podgląd typów kolejki dla admina: kto już typował, a kto jeszcze nie, oraz odpowiedzi wybranego gracza.
 * Tylko odczyt (uprawnienie season-list), nic tu nie zmienia danych.
 */
new class extends Component {
    use WithPagination;

    #[Url(as: 'season', except: 0)]
    public int $seasonId = 0;

    #[Url(as: 'matchday', except: 1)]
    public int $number = 1;

    /** all | tipped | missing */
    #[Url(as: 'show', except: 'all')]
    public string $show = 'all';

    /** Gracz, którego odpowiedzi pokazujemy w oknie podglądu. */
    public ?int $previewUserId = null;

    public function mount(): void
    {
        if (!Season::whereKey($this->seasonId)->exists()) {
            $this->seasonId = Season::current()?->id ?? (int) Season::orderByDesc('number')->value('id');
        }
    }

    public function render(): View
    {
        return $this->view()->title(__('Tips overview'));
    }

    public function updatedSeasonId(): void
    {
        $this->number = 1;
        $this->resetPage();
    }

    public function updatedNumber(): void
    {
        $this->resetPage();
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function seasons()
    {
        return Season::orderByDesc('number')->get();
    }

    #[Computed]
    public function matchdays()
    {
        return Matchday::where('season_id', $this->seasonId)->orderBy('number')->get();
    }

    #[Computed]
    public function matchday(): ?Matchday
    {
        return $this->matchdays->firstWhere('number', $this->number) ?? $this->matchdays->first();
    }

    /** Gracze sezonu (na liście, z rolą i bez bana) – boty nie typują. */
    private function playersQuery()
    {
        return SeasonTeam::query()
            ->where('season_id', $this->seasonId)
            ->whereNotNull('user_id')
            ->whereIn('user_id', Players::eligible()->select('id'));
    }

    /** Liczniki: ilu graczy powinno typować i ilu już typowało. */
    #[Computed]
    public function totals(): array
    {
        if (!$this->matchday) {
            return [0, 0];
        }

        $total = $this->playersQuery()->count();
        $tipped = $this->playersQuery()
            ->whereIn('user_id', Tip::where('matchday_id', $this->matchday->id)->select('user_id'))
            ->count();

        return [$total, $tipped];
    }

    #[Computed]
    public function players()
    {
        $query = $this->playersQuery()->with('user:id,name,team_name')->orderBy('position');

        if ($this->matchday) {
            $tipped = Tip::where('matchday_id', $this->matchday->id)->select('user_id');

            if ($this->show === 'tipped') {
                $query->whereIn('user_id', $tipped);
            } elseif ($this->show === 'missing') {
                $query->whereNotIn('user_id', $tipped);
            }
        }

        return $query->paginate(25);
    }

    /** Typy i liczba odpowiedzi graczy z bieżącej strony: user_id => [Tip, liczba odpowiedzi]. */
    #[Computed]
    public function details(): array
    {
        if (!$this->matchday) {
            return [];
        }

        $ids = $this->players->getCollection()->pluck('user_id')->all();
        $tips = Tip::where('matchday_id', $this->matchday->id)->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $counts = TipAnswer::where('matchday_id', $this->matchday->id)->whereIn('user_id', $ids)
            ->selectRaw('user_id, count(*) as c')->groupBy('user_id')->pluck('c', 'user_id');

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['tip' => $tips->get($id), 'answers' => (int) ($counts[$id] ?? 0)];
        }

        return $out;
    }

    public function openPreview(int $userId): void
    {
        $this->previewUserId = $userId;
        unset($this->preview);
        Flux::modal('tip-preview')->show();
    }

    /**
     * Typ i odpowiedzi wybranego gracza: zestawy pytań kolejki z jego odpowiedzią przy każdym pytaniu.
     *
     * @return array{team: ?SeasonTeam, tip: ?Tip, sets: array<int, array{label: string, sides: array<string, \Illuminate\Support\Collection>}>, answers: array<int, bool>}
     */
    #[Computed]
    public function preview(): array
    {
        if (!$this->matchday || !$this->previewUserId) {
            return ['team' => null, 'tip' => null, 'sets' => [], 'answers' => []];
        }

        $answers = TipAnswer::where('matchday_id', $this->matchday->id)->where('user_id', $this->previewUserId)
            ->pluck('answer', 'matchday_question_id')
            ->map(fn($a) => (bool) $a)
            ->all();

        $sets = MatchdayQuestion::with('question:id,text')
            ->where('matchday_id', $this->matchday->id)
            ->orderBy('position')
            ->get()
            ->groupBy(fn($slot) => $slot->competition_type->value)
            // Tylko zestawy, na które gracz cokolwiek odpowiedział.
            ->filter(fn($slots) => $slots->contains(fn($slot) => array_key_exists($slot->id, $answers)))
            ->map(fn($slots) => [
                'label' => $slots->first()->competition_type->questionSetLabel(),
                'sides' => $slots->groupBy(fn($slot) => $slot->side->value)->all(),
            ])
            ->values()
            ->all();

        return [
            'team' => SeasonTeam::where('season_id', $this->seasonId)->where('user_id', $this->previewUserId)->with('user')->first(),
            'tip' => Tip::where('matchday_id', $this->matchday->id)->where('user_id', $this->previewUserId)->first(),
            'sets' => $sets,
            'answers' => $answers,
        ];
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ __('Tips overview') }}</flux:heading>
            <flux:text>{{ __('Who has already tipped the selected matchday and who has not.') }}</flux:text>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            @if ($this->seasons->isNotEmpty())
                <div class="w-full sm:w-48">
                    <flux:select wire:model.live="seasonId" :label="__('Season')">
                        @foreach ($this->seasons as $option)
                            <flux:select.option :value="$option->id">{{ $option->title }} ({{ $option->status->label() }})
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif

            @if ($this->matchdays->isNotEmpty())
                <div class="w-full sm:w-64">
                    <flux:select wire:model.live="number" :label="__('Matchday')">
                        @foreach ($this->matchdays as $option)
                            <flux:select.option :value="$option->number">
                                {{ $option->number }}. {{ filled($option->opponent) ? $option->fixture : __('not set yet') }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif

            <div class="w-full sm:w-44">
                <flux:select wire:model.live="show" :label="__('Show')">
                    <flux:select.option value="all">{{ __('Everyone') }}</flux:select.option>
                    <flux:select.option value="tipped">{{ __('Already tipped') }}</flux:select.option>
                    <flux:select.option value="missing">{{ __('Not tipped yet') }}</flux:select.option>
                </flux:select>
            </div>
        </div>
    </div>

    @if (!$this->matchday)
        <flux:card>
            <flux:text>{{ __('No matchdays yet.') }}</flux:text>
        </flux:card>
    @else
    <div class="flex flex-wrap items-center gap-2">
        <flux:badge color="green">
            {{ __('Tipped: :done of :total', ['done' => $this->totals[1], 'total' => $this->totals[0]]) }}
        </flux:badge>
        <flux:text size="sm">{{ $this->matchday->fixture }}</flux:text>
    </div>

    <flux:table :paginate="$this->players">
        <flux:table.columns>
            <flux:table.column>{{ __('Place') }}</flux:table.column>
            <flux:table.column>{{ __('Team') }}</flux:table.column>
            <flux:table.column>{{ __('Tip') }}</flux:table.column>
            <flux:table.column>{{ __('Answers') }}</flux:table.column>
            <flux:table.column>{{ __('Saved') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->players as $team)
            @php
                $detail = $this->details[$team->user_id] ?? ['tip' => null, 'answers' => 0];
            @endphp
            <flux:table.row :key="$team->id">
                <flux:table.cell>{{ $team->position }}</flux:table.cell>
                <flux:table.cell>{{ $team->name }}</flux:table.cell>
                <flux:table.cell>
                    @if ($detail['tip'])
                        <flux:badge color="green">{{ $detail['tip']->score() }}</flux:badge>
                    @else
                        <flux:badge color="zinc">—</flux:badge>
                    @endif
                </flux:table.cell>
                <flux:table.cell>{{ $detail['answers'] }}</flux:table.cell>
                <flux:table.cell>{{ $detail['tip']?->saved_at->translatedFormat('j F, H:i:s') ?? '—' }}
                </flux:table.cell>
                <flux:table.cell>
                    @if ($detail['tip'])
                        <flux:button size="xs" variant="ghost" icon="eye" wire:click="openPreview({{ $team->user_id }})">
                            {{ __('Show') }}</flux:button>
                    @endif
                </flux:table.cell>
            </flux:table.row>
            @empty
            <flux:table.row>
                <flux:table.cell colspan="6">{{ __('No players match your filters.') }}</flux:table.cell>
            </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
    @endif
    {{-- Okno: typ i odpowiedzi wybranego gracza --}}
    <flux:modal name="tip-preview" class="w-full md:w-[48rem]">
        @php
            $preview = $this->preview;
        @endphp
        <div class="space-y-4">
            <flux:heading size="lg">{{ $preview['team']?->name ?? __('Tip') }}</flux:heading>

            @if ($preview['tip'])
                <flux:text>
                    {{ __('Saved tip: :score, last change :time.', ['score' => $preview['tip']->score(), 'time' => $preview['tip']->saved_at->translatedFormat('j F, H:i:s')]) }}
                </flux:text>
            @endif

            @forelse ($preview['sets'] as $set)
                <div class="space-y-2">
                    <flux:heading>{{ $set['label'] }}</flux:heading>
                    <div class="grid gap-4 md:grid-cols-2">
                        @foreach ($set['sides'] as $sideValue => $slots)
                            @php
                                $side = \App\Enums\QuestionSide::from($sideValue);
                            @endphp
                            <div class="space-y-1">
                                <flux:badge size="sm" :color="$side->color()">{{ $side->label() }}</flux:badge>
                                @foreach ($slots as $slot)
                                    @php
                                        $given = $preview['answers'][$slot->id] ?? null;
                                    @endphp
                                    <div class="flex items-start justify-between gap-3 text-sm" wire:key="pv-{{ $slot->id }}">
                                        <span class="min-w-0 flex-1">{{ $slot->position }}. {{ $slot->question->text }}</span>
                                        @if ($given === null)
                                            <flux:badge size="sm" color="zinc">{{ __('No answer') }}</flux:badge>
                                        @elseif ($given)
                                            <flux:badge size="sm" color="green">{{ __('Yes') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="red">{{ __('No') }}</flux:badge>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <flux:text>{{ __('No answers to the bonus questions.') }}</flux:text>
            @endforelse
        </div>
    </flux:modal>
</div>