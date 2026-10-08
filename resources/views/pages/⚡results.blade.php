<?php

use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Support\CupBracket;
use App\Support\Standings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Wyniki i tabele aktywnego sezonu na stronie głównej (dla wszystkich, także gości).
 * Ligi i Liga podwórkowa: tabela + mecze wybranej kolejki. Puchar Polski: mecze wybranej rundy.
 * Uwaga: nie nazywaj własności "slots" ani "rows" – Livewire rezerwuje część nazw.
 */
new #[Layout('layouts::public')] class extends Component {
    use WithPagination;

    // Wybrane rozgrywki: league-1..league-10, cup, swiss albo c-ID (rozgrywki ręczne).
    #[Url(as: 'c', except: '')]
    public string $key = '';

    #[Url(as: 'round', except: 0)]
    public int $round = 0;

    public function mount(): void
    {
        // Domyślnie rozgrywki zalogowanego gracza (jego liga albo podwórkowa), inaczej Ekstraklasa.
        if (!array_key_exists($this->key, $this->options)) {
            $this->key = $this->myKey() ?? (array_key_first($this->options) ?? '');
        }

        if ($this->round < 1 || $this->round > Matchday::PER_SEASON) {
            $this->round = max(1, (int) Matchday::where('season_id', $this->season?->id ?? 0)
                ->where('status', \App\Enums\MatchdayStatus::Played)->max('number'));
        }
    }

    public function render(): View
    {
        return $this->view()->title(__('Results and tables'));
    }

    public function updatedKey(): void
    {
        $this->resetPage('matchesPage');
    }

    public function updatedRound(): void
    {
        $this->resetPage('matchesPage');
    }

    /* ==================================================================
     | DANE
     * ================================================================*/

    #[Computed]
    public function season(): ?Season
    {
        return Season::current();
    }

    /** klucz => nazwa rozgrywek sezonu */
    #[Computed]
    public function options(): array
    {
        if (!$this->season) {
            return [];
        }

        $options = [];
        foreach (Competition::where('season_id', $this->season->id)->orderBy('tier')->orderBy('id')->get() as $competition) {
            $options[$this->keyOf($competition)] = $competition->name;
        }

        return $options;
    }

    #[Computed]
    public function competition(): ?Competition
    {
        if (!$this->season) {
            return null;
        }

        return Competition::where('season_id', $this->season->id)->get()->first(fn(Competition $c) => $this->keyOf($c) === $this->key);
    }

    #[Computed]
    public function isCup(): bool
    {
        return $this->competition?->type === CompetitionType::Cup;
    }

    #[Computed]
    public function hasTable(): bool
    {
        return $this->competition !== null && !$this->isCup && $this->competition->type !== CompetitionType::Legends;
    }

    #[Computed]
    public function table()
    {
        return $this->hasTable ? Standings::for($this->competition) : collect();
    }

    #[Computed]
    public function matchday(): ?Matchday
    {
        return $this->season ? Matchday::where('season_id', $this->season->id)->where('number', $this->round)->first() : null;
    }

    #[Computed]
    public function matches()
    {
        if (!$this->competition) {
            return null;
        }

        return Fixture::query()
            ->with(['home.seasonTeam.user:id,name,team_name', 'home.seasonTeam.bot:id,name', 'away.seasonTeam.user:id,name,team_name', 'away.seasonTeam.bot:id,name'])
            ->where('competition_id', $this->competition->id)
            ->where('round', $this->round)
            ->orderBy('id')
            ->paginate(32, pageName: 'matchesPage');
    }

    /** Id zespołu zalogowanego gracza na liście sezonu (do wyróżnienia w tabeli). */
    #[Computed]
    public function myTeamId(): ?int
    {
        if (!auth()->check() || !$this->season) {
            return null;
        }

        return SeasonTeam::where('season_id', $this->season->id)->where('user_id', auth()->id())->value('id');
    }

    /* ==================================================================
     | POMOCNICZE
     * ================================================================*/

    private function myKey(): ?string
    {
        if (!$this->myTeamId) {
            return null;
        }

        $competition = Competition::where('season_id', $this->season->id)
            ->whereIn('type', [CompetitionType::League->value, CompetitionType::Swiss->value])
            ->whereHas('entries', fn($q) => $q->where('season_team_id', $this->myTeamId))
            ->first();

        return $competition ? $this->keyOf($competition) : null;
    }

    private function keyOf(Competition $competition): string
    {
        return match ($competition->type) {
            CompetitionType::League => 'league-' . $competition->tier,
            CompetitionType::Cup => 'cup',
            CompetitionType::Swiss => 'swiss',
            default => 'c-' . $competition->id,
        };
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="space-y-1">
        <flux:heading size="xl" level="1">{{ __('Results and tables') }}</flux:heading>
        @if ($this->season)
            <flux:text>{{ $this->season->title }}</flux:text>
        @endif
    </div>

    @if (!$this->season || count($this->options) === 0)
        <flux:card>
            <flux:text>{{ __('There is no active season right now.') }}</flux:text>
        </flux:card>
    @else
        <div class="flex flex-wrap items-end gap-3">
            <div class="w-full sm:w-72">
                <flux:select wire:model.live="key" :label="__('Competition')">
                    @foreach ($this->options as $optionKey => $optionName)
                        <flux:select.option :value="$optionKey">{{ $optionName }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="w-full sm:w-64">
                <flux:select wire:model.live="round" :label="$this->isCup ? __('Round') : __('Matchday')">
                    @foreach (range(1, \App\Models\Matchday::PER_SEASON) as $number)
                        <flux:select.option :value="$number">
                            {{ $this->isCup ? $number . '. ' . \App\Support\CupBracket::roundName($number) : __('Matchday :number', ['number' => $number]) }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if ($this->matchday && filled($this->matchday->opponent))
            <flux:text>
                {{ $this->matchday->fixture }}
                @if ($this->matchday->lech_goals !== null)
                    &middot; <span class="font-semibold">{{ $this->matchday->is_home ? $this->matchday->lech_goals . ':' . $this->matchday->opponent_goals : $this->matchday->opponent_goals . ':' . $this->matchday->lech_goals }}</span>
                @elseif ($this->matchday->kickoff_at)
                    &middot; {{ $this->matchday->kickoff_at->translatedFormat('j F Y, H:i') }}
                @endif
            </flux:text>
        @endif

        <div class="grid gap-6 {{ $this->hasTable ? 'lg:grid-cols-[1fr_20rem]' : '' }}">
            {{-- ============ Tabela ============ --}}
            @if ($this->hasTable)
                <flux:card class="overflow-x-auto p-0">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-zinc-500">
                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                <th class="px-3 py-2 text-right">#</th>
                                <th class="px-3 py-2 text-left">{{ __('Team') }}</th>
                                <th class="px-2 py-2 text-right" title="{{ __('Matches played') }}">{{ __('P') }}</th>
                                <th class="px-2 py-2 text-right" title="{{ __('Won') }}">{{ __('W') }}</th>
                                <th class="px-2 py-2 text-right" title="{{ __('Drawn') }}">{{ __('D') }}</th>
                                <th class="px-2 py-2 text-right" title="{{ __('Lost') }}">{{ __('L') }}</th>
                                <th class="px-2 py-2 text-right" title="{{ __('Goals') }}">{{ __('Goals') }}</th>
                                <th class="px-2 py-2 text-right" title="{{ __('Exact tips') }}">{{ __('Ex.') }}</th>
                                <th class="px-2 py-2 text-right" title="{{ __('Bonus') }}">{{ __('Bon.') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Pts') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->table as $index => $row)
                                <tr wire:key="row-{{ $row['entry_id'] }}"
                                    class="border-b border-zinc-100 last:border-0 dark:border-zinc-700/50 {{ $row['team']->id == $this->myTeamId ? 'bg-amber-50 font-semibold dark:bg-amber-900/20' : '' }}">
                                    <td class="px-3 py-1.5 text-right tabular-nums text-zinc-500">{{ $index + 1 }}.</td>
                                    <td class="max-w-56 truncate px-3 py-1.5">{{ $row['team']->name }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['played'] }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['won'] }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['drawn'] }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['lost'] }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['for'] }}:{{ $row['against'] }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['exact'] }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['bonus'] }}</td>
                                    <td class="px-3 py-1.5 text-right font-semibold tabular-nums">{{ $row['points'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </flux:card>
            @endif

            {{-- ============ Mecze kolejki / rundy ============ --}}
            <flux:card class="space-y-2 self-start">
                <flux:heading>
                    {{ $this->isCup ? \App\Support\CupBracket::roundName($round) : __('Matchday :number', ['number' => $round]) }}
                </flux:heading>

                @if ($this->matches && $this->matches->isNotEmpty())
                    <ul class="space-y-1.5">
                        @foreach ($this->matches as $fixture)
                            @php
                                $mine = $this->myTeamId && in_array($this->myTeamId, [$fixture->home?->season_team_id, $fixture->away?->season_team_id]);
                            @endphp
                            <li wire:key="fx-{{ $fixture->id }}"
                                class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 text-sm {{ $mine ? 'font-semibold' : '' }}">
                                <span class="truncate text-right {{ $fixture->winner_entry_id && $fixture->winner_entry_id === $fixture->home_entry_id && $this->isCup ? 'text-green-700 dark:text-green-400' : '' }}">
                                    {{ $fixture->home?->seasonTeam->name ?? __('Seat :number', ['number' => $fixture->home_seat]) }}
                                </span>
                                <span class="tabular-nums {{ $fixture->isPlayed() ? '' : 'text-zinc-400' }}">{{ $fixture->score() }}</span>
                                <span class="truncate {{ $fixture->winner_entry_id && $fixture->winner_entry_id === $fixture->away_entry_id && $this->isCup ? 'text-green-700 dark:text-green-400' : '' }}">
                                    @if ($fixture->away)
                                        {{ $fixture->away->seasonTeam->name }}
                                    @elseif ($fixture->away_seat)
                                        {{ __('Seat :number', ['number' => $fixture->away_seat]) }}
                                    @else
                                        <span class="italic">{{ \App\Models\Fixture::VIRTUAL_OPPONENT }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($this->matches->hasPages())
                        <div class="pt-2">{{ $this->matches->links() }}</div>
                    @endif
                @else
                    <flux:text>{{ __('No matches yet.') }}</flux:text>
                @endif
            </flux:card>
        </div>
    @endif
</div>
