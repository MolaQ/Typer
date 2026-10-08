<?php

use App\Actions\Tips\SaveTip;
use App\Enums\QuestionSide;
use App\Models\Matchday;
use App\Models\MatchdayQuestion;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\TeamScore;
use App\Models\Tip;
use App\Models\TipAnswer;
use App\Support\PlayerCompetitions;
use App\Support\Players;
use App\Support\TipRules;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Typowanie gracza: wynik meczu Lecha + odpowiedzi tak/nie na pytania bonusowe (po jednym zestawie
 * 5+5 na każdy typ rozgrywek, w którym gracz gra). Zapis możliwy do godziny pierwszego gwizdka.
 * Uwaga: nie nazywaj własności "slots" ani "rows" – Livewire rezerwuje część nazw.
 */
new #[Layout('layouts::public')] class extends Component {
    #[Url(as: 'matchday', except: 0)]
    public int $number = 0;

    /** Wpisany wynik (teksty, bo pole formularza może być puste). */
    public string $lech = '';
    public string $opponent = '';

    /** matchday_question_id => '1' | '0' | '' */
    public array $answers = [];

    public function mount(): void
    {
        if ($this->number < 1 || !$this->matchdays->firstWhere('number', $this->number)) {
            // Domyślnie pierwsza kolejka, na którą można jeszcze typować; w razie braku pierwsza.
            $open = $this->matchdays->first(fn($m) => $m->isOpenForTips());
            $this->number = $open?->number ?? ($this->matchdays->first()?->number ?? 0);
        }

        $this->loadTip();
    }

    public function render(): View
    {
        return $this->view()->title(__('My tips'));
    }

    public function updatedNumber(): void
    {
        unset($this->matchday, $this->tip, $this->isOpen, $this->sets, $this->scores);
        $this->loadTip();
    }

    /** Wczytuje zapisany typ i odpowiedzi gracza do pól formularza. */
    private function loadTip(): void
    {
        $this->lech = '';
        $this->opponent = '';
        $this->answers = [];

        $matchday = $this->matchday;

        if (!$matchday) {
            return;
        }

        $tip = Tip::where('matchday_id', $matchday->id)->where('user_id', auth()->id())->first();

        if ($tip) {
            $this->lech = (string) $tip->lech_goals;
            $this->opponent = (string) $tip->opponent_goals;
        }

        $this->answers = TipAnswer::where('matchday_id', $matchday->id)->where('user_id', auth()->id())
            ->pluck('answer', 'matchday_question_id')
            ->map(fn($a) => $a ? '1' : '0')
            ->all();
    }

    /* ==================================================================
     | DANE
     * ================================================================*/

    #[Computed]
    public function season(): ?Season
    {
        return Season::current();
    }

    /** Czy to konto w ogóle może grać w tym sezonie (rola, brak bana, jest na liście)? */
    #[Computed]
    public function canPlay(): bool
    {
        $user = auth()->user();

        return $this->season
            && Players::canPlay($user)
            && SeasonTeam::where('season_id', $this->season->id)->where('user_id', $user->id)->exists();
    }

    #[Computed]
    public function matchdays()
    {
        return $this->season
            ? Matchday::where('season_id', $this->season->id)->orderBy('number')->get()
            : collect();
    }

    #[Computed]
    public function matchday(): ?Matchday
    {
        return $this->matchdays->firstWhere('number', $this->number);
    }

    #[Computed]
    public function isOpen(): bool
    {
        return $this->matchday?->isOpenForTips() ?? false;
    }

    #[Computed]
    public function tip(): ?Tip
    {
        return $this->matchday
            ? Tip::where('matchday_id', $this->matchday->id)->where('user_id', auth()->id())->first()
            : null;
    }

    /**
     * Zestawy pytań gracza: lista [typ, [strona => miejsca]] tylko dla typów, w których gra.
     *
     * @return array<int, array{type: \App\Enums\CompetitionType, sides: array<string, \Illuminate\Support\Collection>}>
     */
    #[Computed]
    public function sets(): array
    {
        if (!$this->matchday || !$this->canPlay) {
            return [];
        }

        $types = PlayerCompetitions::types(auth()->id(), $this->season->id);
        $all = MatchdayQuestion::with('question:id,text')
            ->where('matchday_id', $this->matchday->id)
            ->orderBy('position')
            ->get();

        $sets = [];
        foreach ($types as $type) {
            $own = $all->where('competition_type', $type);
            if ($own->isEmpty()) {
                continue;
            }
            $sets[] = [
                'type' => $type,
                'sides' => [
                    QuestionSide::Offensive->value => $own->where('side', QuestionSide::Offensive)->values(),
                    QuestionSide::Defensive->value => $own->where('side', QuestionSide::Defensive)->values(),
                ],
            ];
        }

        return $sets;
    }

    /** Rozliczenie kolejki po wpisaniu wyniku: zestaw pytań (wartość typu) => TeamScore. */
    #[Computed]
    public function scores()
    {
        if (!$this->matchday || $this->matchday->status !== \App\Enums\MatchdayStatus::Played) {
            return collect();
        }

        $teamId = SeasonTeam::where('season_id', $this->season->id)->where('user_id', auth()->id())->value('id');

        return TeamScore::where('matchday_id', $this->matchday->id)->where('season_team_id', $teamId)->get()
            ->keyBy(fn($score) => $score->question_set->value);
    }

    /** Ile pytań łącznie i ile już ma odpowiedź (do paska postępu). */
    public function progress(): array
    {
        $ids = collect($this->sets)->flatMap(fn($s) => collect($s['sides'])->flatten(1)->pluck('id'));
        $answered = $ids->filter(fn($id) => in_array($this->answers[$id] ?? '', ['0', '1'], true))->count();

        return [$answered, $ids->count()];
    }

    /* ==================================================================
     | AKCJE
     * ================================================================*/

    public function save(SaveTip $action): void
    {
        $matchday = $this->matchday;

        if (!$matchday) {
            return;
        }

        try {
            $action->handle(auth()->user(), $matchday, $this->lech, $this->opponent, $this->answers);
        } catch (DomainException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        unset($this->tip);
        $this->loadTip();

        Flux::toast(variant: 'success', text: __('Tip saved.'));
    }
}; ?>

<div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <div class="space-y-1">
        <flux:heading size="xl" level="1">{{ __('My tips') }}</flux:heading>
        <flux:text>
            {{ __('Tip the score of the Lech match and answer the bonus questions. You can change your tip until kickoff.') }}
        </flux:text>
    </div>

    @if (!$this->season)
        <flux:card>
            <flux:text>{{ __('There is no active season right now.') }}</flux:text>
        </flux:card>
    @elseif (!$this->canPlay)
        <flux:card class="space-y-1">
            <flux:heading>{{ __('You are not taking part in this season') }}</flux:heading>
            <flux:text>
                {{ __('Your account needs a role (without a ban) and a place on the team list. Contact the administrator.') }}
            </flux:text>
        </flux:card>
    @elseif ($this->matchdays->isEmpty() || !$this->matchday)
        <flux:card>
            <flux:text>{{ __('No matchdays yet.') }}</flux:text>
        </flux:card>
    @else
    <div class="w-full sm:w-80">
        <flux:select wire:model.live="number" :label="__('Matchday')">
            @foreach ($this->matchdays as $option)
                <flux:select.option :value="$option->number">
                    {{ $option->number }}. {{ filled($option->opponent) ? $option->fixture : __('not set yet') }}
                </flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:card class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('Matchday :number', ['number' => $this->matchday->number]) }}:
                    {{ $this->matchday->fixture }}
                </flux:heading>
                <flux:text>
                    {{ $this->matchday->kickoff_at ? $this->matchday->kickoff_at->translatedFormat('j F Y, H:i') : __('not set yet') }}
                    @if ($this->matchday->competition) · {{ $this->matchday->competition }} @endif
                </flux:text>
            </div>

            @if ($this->isOpen)
                <flux:badge color="green">{{ __('Open') }}</flux:badge>
            @else
                <flux:badge color="zinc">{{ __('Closed') }}</flux:badge>
            @endif
        </div>

        @if (!$this->matchday->isFilled())
            <flux:text>{{ __('The opponent and date are not set yet, so tipping is not open.') }}</flux:text>
        @else
            {{-- Wynik: zawsze z perspektywy Lecha (Lech : rywal), bez względu na gospodarza. --}}
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-28">
                    <flux:input type="number" min="0" max="{{ \App\Support\TipRules::MAX_GOALS }}" wire:model="lech"
                        :label="'Lech Poznań'" :disabled="! $this->isOpen" />
                </div>
                <span class="pb-2 text-lg font-semibold">:</span>
                <div class="w-28">
                    <flux:input type="number" min="0" max="{{ \App\Support\TipRules::MAX_GOALS }}" wire:model="opponent"
                        :label="$this->matchday->opponent" :disabled="! $this->isOpen" />
                </div>
            </div>

            @if ($this->matchday->lech_goals !== null)
                <flux:text>
                    {{ __('Final score: :score.', ['score' => 'Lech ' . $this->matchday->lech_goals . ':' . $this->matchday->opponent_goals . ' ' . $this->matchday->opponent]) }}
                    @if ($first = $this->scores->first())
                        {{ __('Points for the tip: :points of 3.', ['points' => $first->tip_points]) }}
                    @endif
                </flux:text>
            @endif

            @if ($this->tip)
                <flux:text size="sm">
                    {{ __('Saved tip: :score, last change :time.', ['score' => $this->tip->score(), 'time' => $this->tip->saved_at->translatedFormat('j F, H:i:s')]) }}
                </flux:text>
            @else
                <flux:text size="sm">{{ __('You have not tipped this matchday yet.') }}</flux:text>
            @endif
        @endif
    </flux:card>

    @if ($this->matchday->isFilled())
    @if (count($this->sets) === 0)
        <flux:card>
            <flux:text>{{ __('The bonus questions for this matchday are not ready yet.') }}</flux:text>
        </flux:card>
    @else
    @php($progress = $this->progress())
    <flux:text size="sm">
        {{ __('Answered :done of :total questions. Unanswered questions score nothing.', ['done' => $progress[0], 'total' => $progress[1]]) }}
    </flux:text>

    @foreach ($this->sets as $set)
    <flux:card class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg">{{ $set['type']->questionSetLabel() }}</flux:heading>

            @if ($score = $this->scores->get($set['type']->value))
                <div class="flex flex-wrap gap-2">
                    <flux:badge color="green">
                        {{ __('Offence: :points', ['points' => $score->offense]) }}{{ $score->offense_zeroed ? ' (' . __('bonus zeroed') . ')' : '' }}
                    </flux:badge>
                    <flux:badge color="blue">
                        {{ __('Defence: :points', ['points' => $score->defense_bonus]) }}{{ $score->defense_zeroed ? ' (' . __('bonus zeroed') . ')' : '' }}
                    </flux:badge>
                </div>
            @endif
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            @foreach ($set['sides'] as $sideValue => $items)
            @php($side = \App\Enums\QuestionSide::from($sideValue))
            <div class="space-y-3">
                <flux:badge :color="$side->color()">{{ $side->label() }}</flux:badge>

                @foreach ($items as $item)
                    <div class="space-y-1 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700"
                        wire:key="q-{{ $item->id }}">
                        <flux:text class="font-medium text-zinc-800 dark:text-zinc-100">{{ $item->position }}.
                            {{ $item->question->text }}
                        </flux:text>
                        @if ($item->correct_answer !== null && $this->matchday->status === \App\Enums\MatchdayStatus::Played)
                            @php
                                $given = $answers[$item->id] ?? '';
                                $answerClass = $given === '' ? '' : (($given === '1') === $item->correct_answer ? 'text-green-700 dark:text-green-400' : 'text-red-600 dark:text-red-400');
                            @endphp
                            <flux:text size="sm" :class="$answerClass">
                                {{ __('Correct answer: :answer', ['answer' => $item->correct_answer ? __('Yes') : __('No')]) }}
                            </flux:text>
                        @endif
                        <div class="flex gap-5">
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" wire:model="answers.{{ $item->id }}" value="1"
                                    @disabled(!$this->isOpen)> {{ __('Yes') }}
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" wire:model="answers.{{ $item->id }}" value="0"
                                    @disabled(!$this->isOpen)> {{ __('No') }}
                            </label>
                        </div>
                    </div>
                @endforeach
            </div>
            @endforeach
        </div>
    </flux:card>
    @endforeach
    @endif

    @if ($this->isOpen)
        <div>
            <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">{{ __('Save tip') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
            </flux:button>
        </div>
    @else
        <flux:text>{{ __('Tipping for this matchday is closed.') }}</flux:text>
    @endif
    @endif
    @endif
</div>