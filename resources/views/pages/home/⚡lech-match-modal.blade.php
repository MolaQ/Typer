<?php

use App\Models\Matchday;
use App\Support\MatchdayInsights;
use App\Support\Premium;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Okno meczu Lecha w kolejce, osadzone raz w layoucie publicznym. Otwiera je zdarzenie „show-lech-match” z id kolejki
 * (panel meczu Lecha na stronie wyników). Szczegóły (premium): liczba typów, rozkład Lech / remis / rywal,
 * a po wyniku trafienia i najczęstsze typy. Bez premium są zablokowane z zachętą (x-premium-lock).
 */
new class extends Component {
    public ?int $matchdayId = null;

    #[On('show-lech-match')]
    public function open(int $id): void
    {
        $this->matchdayId = $id;
        unset($this->matchday, $this->tips);
        Flux::modal('lech-match')->show();
    }

    #[Computed]
    public function matchday(): ?Matchday
    {
        return $this->matchdayId ? Matchday::find($this->matchdayId) : null;
    }

    #[Computed]
    public function premium(): bool
    {
        return auth()->check() && Premium::isActive(auth()->user());
    }

    #[Computed]
    public function tips(): ?array
    {
        return $this->matchday && $this->premium ? MatchdayInsights::tips($this->matchday) : null;
    }
}; ?>

<div>
    <flux:modal name="lech-match" class="w-full max-w-2xl">
        @if ($matchday = $this->matchday)
            @php
                $played = $matchday->lech_goals !== null && $matchday->opponent_goals !== null;
                $tips = $this->tips;
                $percent = fn (int $part, int $total) => $total > 0 ? round($part / $total * 100) : 0;
            @endphp
            <div class="space-y-5">
                {{-- Nagłówek: Lech zawsze po lewej, rozgrywki meczu i DOM/WYJAZD w barwach strony --}}
                <div class="lech-banner space-y-2 rounded-2xl px-5 py-4 text-center">
                    <div class="text-xs font-semibold uppercase tracking-widest text-lech-200">
                        {{ __('Matchday :number', ['number' => $matchday->number]) }}
                        @if ($matchday->competitionLabel()) · {{ $matchday->competitionLabel() }} @endif
                    </div>
                    <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-3">
                        <span class="text-right text-lg font-bold">Lech Poznań</span>
                        <span class="rounded-lg bg-white/15 px-3 py-1 text-2xl font-black tabular-nums">
                            {{ $played ? $matchday->lech_goals . ':' . $matchday->opponent_goals : '–:–' }}
                        </span>
                        <span class="text-left text-lg font-bold">{{ $matchday->opponent ?? '—' }}</span>
                    </div>
                    <div class="flex items-center justify-center gap-2 text-xs">
                        <span class="rounded-full bg-white/15 px-2 py-0.5 font-semibold uppercase tracking-wide">{{ $matchday->is_home ? __('HOME') : __('AWAY') }}</span>
                        @if ($matchday->kickoff_at)
                            <span class="text-lech-100">{{ $matchday->kickoff_at->translatedFormat('j F Y, H:i') }}</span>
                        @endif
                    </div>
                </div>

                {{-- Protokół meczu (zawodnicy Lecha), dla wszystkich --}}
                @php
                    $protocol = $played && $matchday->lech_match_id
                        ? \App\Models\MatchEvent::with(['player:id,name', 'other:id,name'])->where('lech_match_id', $matchday->lech_match_id)->orderBy('id')->get()
                        : collect();
                @endphp
                @if ($protocol->isNotEmpty())
                    <div class="rounded-xl border border-zinc-200 px-3 py-1 dark:border-zinc-700">
                        @include('partials.match-protocol', ['events' => $protocol])
                    </div>
                @endif

                {{-- ===== Szczegóły (premium) ===== --}}
                <div class="space-y-4">
                    @if (!$this->premium)
                        <x-premium-lock :title="__('Match details')"
                            :text="__('The number of tips, how the players split between a Lech win, a draw and a win for the opponent, the share of exact tips and the most popular scores.')" />
                    @elseif ($tips['count'] === 0)
                        <flux:text>{{ __('Nobody has tipped this match yet.') }}</flux:text>
                    @else
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <div class="rounded-xl border border-zinc-200 p-3 text-center dark:border-zinc-700">
                                <div class="text-2xl font-black tabular-nums">{{ $tips['count'] }}</div>
                                <div class="text-xs text-zinc-500">{{ __('Tips') }}</div>
                            </div>
                            <div class="rounded-xl border border-zinc-200 p-3 text-center dark:border-zinc-700">
                                <div class="text-2xl font-black tabular-nums">{{ number_format((float) $tips['avg_lech'], 1, ',', '') }}:{{ number_format((float) $tips['avg_opponent'], 1, ',', '') }}</div>
                                <div class="text-xs text-zinc-500">{{ __('Average tip') }}</div>
                            </div>
                            @if ($played)
                                <div class="rounded-xl border border-zinc-200 p-3 text-center dark:border-zinc-700">
                                    <div class="text-2xl font-black tabular-nums">{{ $tips['exact'] }}%</div>
                                    <div class="text-xs text-zinc-500">{{ __('Exact score (:count)', ['count' => $tips['exact_count']]) }}</div>
                                </div>
                                <div class="rounded-xl border border-zinc-200 p-3 text-center dark:border-zinc-700">
                                    <div class="text-2xl font-black tabular-nums">{{ $tips['outcome'] }}%</div>
                                    <div class="text-xs text-zinc-500">{{ __('Correct outcome') }}</div>
                                </div>
                            @endif
                        </div>

                        {{-- Rozkład: wygrana Lecha, remis, wygrana rywala --}}
                        <div class="space-y-1.5">
                            <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('How the players tipped') }}</div>
                            <div class="flex h-6 overflow-hidden rounded-full text-[11px] font-bold text-white">
                                @foreach ([['lech', 'bg-lech-700', 'Lech'], ['draw', 'bg-zinc-400', __('Draw result')], ['opponent', 'bg-zinc-700', $matchday->opponent ?? __('Opponent')]] as [$part, $color, $label])
                                    @if ($tips[$part] > 0)
                                        <span class="{{ $color }} flex items-center justify-center" style="width: {{ $percent($tips[$part], $tips['count']) }}%"
                                            title="{{ $label }}: {{ $tips[$part] }}">{{ $percent($tips[$part], $tips['count']) }}%</span>
                                    @endif
                                @endforeach
                            </div>
                            <div class="flex justify-between text-xs text-zinc-500">
                                <span>Lech: {{ $tips['lech'] }}</span>
                                <span>{{ __('Draw result') }}: {{ $tips['draw'] }}</span>
                                <span>{{ $matchday->opponent ?? __('Opponent') }}: {{ $tips['opponent'] }}</span>
                            </div>
                        </div>

                        @if ($played)
                            <div class="space-y-1.5">
                                <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Most popular tips') }}</div>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($tips['popular'] as $popular)
                                        <span class="{{ $popular['score'] === $matchday->lech_goals . ':' . $matchday->opponent_goals ? 'border-green-500 bg-green-50 dark:bg-green-500/10' : 'border-zinc-200 dark:border-zinc-700' }} rounded-lg border px-3 py-1.5 text-sm">
                                            <span class="font-bold tabular-nums">{{ $popular['score'] }}</span>
                                            <span class="text-xs text-zinc-500">× {{ $popular['count'] }}</span>
                                        </span>
                                    @endforeach
                                </div>
                                <flux:text class="text-xs">{{ __('Correct goal difference: :percent%', ['percent' => $tips['diff']]) }}</flux:text>
                            </div>
                        @else
                            <flux:text class="text-xs italic">{{ __('Exact tips and the most popular scores appear after the result.') }}</flux:text>
                        @endif
                    @endif
                </div>
            </div>
        @endif
    </flux:modal>
</div>
