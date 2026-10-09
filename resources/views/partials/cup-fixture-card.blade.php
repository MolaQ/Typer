{{--
    Karta meczu Pucharu Polski (strona wyników). Dwa wiersze: miejsce w drabince, zespół, gole.
    Zwycięzca na zielono z paskiem z lewej, przegrany wyszarzony; remis rozstrzygnięty czasem typu jako „po karnych”.
    Parametry: $fixture (Fixture z home/away.seasonTeam), $mine (mecz zalogowanego gracza).
--}}
@php
    $played = $fixture->isPlayed();
    $sides = [
        ['entry' => $fixture->home, 'entry_id' => $fixture->home_entry_id, 'seat' => $fixture->home_seat, 'goals' => $fixture->home_goals],
        ['entry' => $fixture->away, 'entry_id' => $fixture->away_entry_id, 'seat' => $fixture->away_seat, 'goals' => $fixture->away_goals],
    ];
@endphp
<button type="button" wire:click="showFixture({{ $fixture->id }})" title="{{ __('Match details') }}"
    class="{{ $mine ? 'ring-2 ring-amber-400 dark:ring-amber-500' : '' }} group flex w-full flex-col overflow-hidden rounded-lg border border-zinc-200 bg-white text-left text-sm shadow-xs transition hover:-translate-y-px hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900">
    @foreach ($sides as $side)
        @php
            $won = $played && $fixture->winner_entry_id && $fixture->winner_entry_id === $side['entry_id'];
            $lost = $played && $fixture->winner_entry_id && !$won;
            $rowClass = match (true) {
                $won => 'border-l-green-500 bg-green-50 font-semibold text-green-900 dark:bg-green-900/20 dark:text-green-200',
                $lost => 'border-l-red-300 text-zinc-400 dark:border-l-red-800',
                default => 'border-l-zinc-200 dark:border-l-zinc-700',
            };
        @endphp
        <div class="{{ $rowClass }} flex items-center gap-2 border-l-4 px-2 py-1.5 {{ $loop->first ? 'border-b border-b-zinc-100 dark:border-b-zinc-800' : '' }}">
            <span class="w-7 shrink-0 rounded bg-zinc-100 py-0.5 text-center text-[10px] tabular-nums text-zinc-500 dark:bg-zinc-800">{{ $side['seat'] }}</span>
            <span class="min-w-0 flex-1 truncate">
                {{ $side['entry']?->seasonTeam->name ?? __('Seat :number', ['number' => $side['seat']]) }}
            </span>
            @if ($won)
                <flux:icon.chevron-double-right variant="micro" class="shrink-0 text-green-600 dark:text-green-400" />
            @endif
            <span class="w-5 shrink-0 text-right font-bold tabular-nums {{ $played ? '' : 'text-zinc-300 dark:text-zinc-600' }}">{{ $side['goals'] ?? '–' }}</span>
        </div>
    @endforeach
    @if ($fixture->decided_by_time)
        <div class="bg-zinc-50 px-2 py-0.5 text-right text-[10px] italic text-zinc-500 dark:bg-zinc-800/60">{{ __('after penalties') }}</div>
    @endif
</button>
