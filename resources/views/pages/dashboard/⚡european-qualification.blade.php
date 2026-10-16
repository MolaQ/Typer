<?php

use App\Enums\Permission;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Support\Audit;
use App\Support\EuropeanQualification;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kwalifikacje do pucharów europejskich: kolejność kryteriów (tytuły poprzedniego sezonu) ustawiana strzałkami
 * i podgląd listy kwalifikacji dla wybranego sezonu (kto zagra w LM, LE i LK). Po kryteriach zawsze idą tabele lig
 * (od Ekstraklasy, według miejsca) i Liga podwórkowa, a ludzie są przed botami (EuropeanQualification).
 * Podgląd: season-list, zmiany: season-edit.
 */
new class extends Component {
    #[Url(as: 'season', except: 0)]
    public int $seasonId = 0;

    /** @var list<string> */
    public array $criteria = [];

    public function mount(): void
    {
        $this->criteria = EuropeanQualification::order();

        if (!Season::whereKey($this->seasonId)->exists()) {
            $this->seasonId = (int) Season::orderByDesc('number')->value('id');
        }
    }

    public function render(): View
    {
        return $this->view()->title(__('European qualification'));
    }

    #[Computed]
    public function seasons()
    {
        return Season::orderByDesc('number')->get();
    }

    /**
     * Podgląd: 72 pierwsze zespoły listy kwalifikacji z pucharem, rozstawieniem i powodem.
     *
     * @return list<array{cup: string, seed: int, team: ?SeasonTeam, reason: string}>
     */
    #[Computed]
    public function preview(): array
    {
        $season = Season::find($this->seasonId);

        if (!$season) {
            return [];
        }

        $ranking = array_slice(EuropeanQualification::ranking($season), 0, EuropeanQualification::PER_CUP * 3);
        $teams = SeasonTeam::with(['user', 'bot'])->whereIn('id', array_column($ranking, 'team'))->get()->keyBy('id');
        $cups = [__('Champions League'), __('Europa League'), __('Conference League')];
        $labels = EuropeanQualification::labels() + ['fill' => __('League table'), 'list' => __('Season list')];

        $rows = [];
        foreach ($ranking as $index => $row) {
            $rows[] = [
                'cup' => $cups[intdiv($index, EuropeanQualification::PER_CUP)],
                'seed' => $index % EuropeanQualification::PER_CUP + 1,
                'team' => $teams->get($row['team']),
                'reason' => $labels[$row['reason']] ?? $row['reason'],
            ];
        }

        return $rows;
    }

    public function move(int $index, int $step): void
    {
        $this->authorizeEdit();
        $target = $index + $step;

        if (!isset($this->criteria[$index], $this->criteria[$target])) {
            return;
        }

        [$this->criteria[$index], $this->criteria[$target]] = [$this->criteria[$target], $this->criteria[$index]];
        $this->persist();
    }

    public function resetOrder(): void
    {
        $this->authorizeEdit();
        EuropeanQualification::resetOrder();
        $this->criteria = EuropeanQualification::order();
        Audit::log('european_qualification.reset', null, [], ['order' => implode(', ', $this->criteria)], __('European qualification'));
        unset($this->preview);
    }

    private function persist(): void
    {
        $before = EuropeanQualification::order();
        EuropeanQualification::saveOrder($this->criteria);
        Audit::log('european_qualification.updated', null, ['order' => implode(', ', $before)], ['order' => implode(', ', $this->criteria)], __('European qualification'));
        unset($this->preview);
    }

    private function authorizeEdit(): void
    {
        abort_unless(auth()->user()?->can(Permission::SeasonEdit->value), 403);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('European qualification') }}</flux:heading>
        <flux:subheading>{{ __('The first 24 teams of the qualification list play in the Champions League, the next 24 in the Europa League and the next 24 in the Conference League. Each team counts once, at its highest title. Human teams come first, bots only fill the free places.') }}</flux:subheading>
    </div>

    <div class="grid gap-6 xl:grid-cols-[26rem_1fr]">
        <flux:card class="space-y-3 self-start">
            <div class="flex items-center justify-between gap-2">
                <flux:heading>{{ __('Order of the criteria') }}</flux:heading>
                @can(\App\Enums\Permission::SeasonEdit->value)
                    <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="resetOrder">{{ __('Default order') }}</flux:button>
                @endcan
            </div>
            <ol class="space-y-1">
                @foreach ($criteria as $index => $key)
                    <li class="flex items-center gap-2 rounded-lg border border-zinc-200 px-2 py-1.5 text-sm dark:border-zinc-700" wire:key="criterion-{{ $key }}">
                        <span class="w-6 text-right text-xs tabular-nums text-zinc-400">{{ $index + 1 }}.</span>
                        <span class="min-w-0 flex-1">{{ \App\Support\EuropeanQualification::labels()[$key] ?? $key }}</span>
                        @can(\App\Enums\Permission::SeasonEdit->value)
                            <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="move({{ $index }}, -1)" :disabled="$index === 0" :aria-label="__('Up')" />
                            <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="move({{ $index }}, 1)" :disabled="$index === count($criteria) - 1" :aria-label="__('Down')" />
                        @endcan
                    </li>
                @endforeach
                <li class="flex items-center gap-2 rounded-lg border border-dashed border-zinc-300 px-2 py-1.5 text-sm text-zinc-500 dark:border-zinc-600">
                    <span class="w-6 text-right text-xs tabular-nums">{{ count($criteria) + 1 }}.</span>
                    <span>{{ __('The rest by league level and table place (Ekstraklasa 2-10, I liga … C klasa, then Liga podwórkowa)') }}</span>
                </li>
            </ol>
            <flux:text class="text-xs">{{ __('In season 1 (no previous season) the teams go in the order of the season list.') }}</flux:text>
        </flux:card>

        <flux:card class="space-y-3">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <flux:heading>{{ __('Preview') }}</flux:heading>
                @if ($this->seasons->isNotEmpty())
                    <div class="w-full sm:w-56">
                        <flux:select wire:model.live="seasonId" :label="__('Season')">
                            @foreach ($this->seasons as $option)
                                <flux:select.option :value="$option->id">{{ $option->title }} ({{ $option->status->label() }})</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                @endif
            </div>
            <flux:text class="text-xs">{{ __('The cups are created with these teams when the season is approved. Changing the order later does not change existing cups.') }}</flux:text>

            @if ($this->preview === [])
                <flux:text>{{ __('The season has no team list yet.') }}</flux:text>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Competition') }}</flux:table.column>
                        <flux:table.column>{{ __('Seed') }}</flux:table.column>
                        <flux:table.column>{{ __('Team') }}</flux:table.column>
                        <flux:table.column>{{ __('Reason') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->preview as $index => $row)
                            <flux:table.row :key="'q-'.$index">
                                <flux:table.cell class="text-zinc-500">{{ $row['cup'] }}</flux:table.cell>
                                <flux:table.cell class="tabular-nums">{{ $row['seed'] }}</flux:table.cell>
                                <flux:table.cell variant="strong">
                                    {{ $row['team']?->name ?? '—' }}
                                    @if ($row['team']?->is_bot)
                                        <flux:badge size="sm" color="zinc">{{ __('Bot') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="text-sm text-zinc-500">{{ $row['reason'] }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </flux:card>
    </div>
</div>
