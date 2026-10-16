<?php

use App\Models\Bot;
use App\Models\OfficeRequest;
use App\Support\BotNames;
use App\Support\Office;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Biuro skarg i wniosków (App\Support\Office): formularz sprawy (odwołanie od bana, od statusu Inactive, wniosek
 * o zmianę nazwy bota dla premium, inna sprawa), głosowanie graczy nad wnioskami o nazwy botów, moje sprawy
 * z odpowiedzią admina i rozpatrzone wnioski o nazwy botów.
 */
new #[Layout('layouts::public')] class extends Component {
    use WithPagination;

    /** 'voting', 'mine' albo 'decided'. */
    public string $tab = 'voting';

    public string $type = '';

    public string $body = '';

    public string $botSearch = '';

    public ?int $botId = null;

    public string $botName = '';

    public function mount(): void
    {
        $this->type = array_key_first($this->types) ?? '';
    }

    public function render(): View
    {
        return $this->view()->title(__('Complaints and requests office'));
    }

    /** @return array<string, string> */
    #[Computed]
    public function types(): array
    {
        $user = auth()->user();

        return $user ? Office::availableTypes($user) : [];
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function updatedBotSearch(): void
    {
        unset($this->botOptions);
    }

    /** Boty do wyboru we wniosku o zmianę nazwy (wyszukiwanie od 2 liter). */
    #[Computed]
    public function botOptions()
    {
        $needle = trim($this->botSearch);

        if (mb_strlen($needle) < 2) {
            return collect();
        }

        return Bot::where('name', 'like', '%' . $needle . '%')->orderBy('name')->limit(8)->get(['id', 'name', 'is_active']);
    }

    #[Computed]
    public function selectedBot(): ?Bot
    {
        return $this->botId ? Bot::find($this->botId) : null;
    }

    public function pickBot(int $id): void
    {
        $this->botId = Bot::whereKey($id)->value('id');
        $this->botSearch = '';
        unset($this->selectedBot, $this->botOptions);
    }

    public function send(): void
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $this->body = trim($this->body);
        $this->botName = Str::squish($this->botName);
        $rename = $this->type === OfficeRequest::BOT_RENAME;

        $this->validate([
            'type' => ['required', 'in:' . implode(',', array_keys($this->types))],
            'body' => ['required', 'string', 'min:10', 'max:' . Office::BODY_MAX],
            'botId' => $rename ? ['required', 'integer', 'exists:bots,id'] : ['nullable'],
            'botName' => $rename ? BotNames::rules($this->botId) : ['nullable'],
        ], [], ['body' => __('Justification'), 'botId' => __('Bot'), 'botName' => __('New bot name')]);

        try {
            Office::submit($user, ['type' => $this->type, 'body' => $this->body, 'bot_id' => $this->botId, 'bot_name' => $this->botName]);
        } catch (DomainException $e) {
            $this->addError('type', $e->getMessage());

            return;
        }

        $this->reset('body', 'botSearch', 'botId', 'botName');
        $this->tab = $rename ? 'voting' : 'mine';
        $this->resetPage();
        unset($this->requests);

        Flux::toast(variant: 'success', text: $rename
            ? __('Your request is now open to the players\' vote.')
            : __('Your request was sent to the administrator.'));
    }

    public function vote(int $id, int $vote): void
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $request = OfficeRequest::findOrFail($id);
        Office::vote($request, $user, $vote);
        unset($this->requests, $this->myVotes);

        if ($request->fresh()?->status !== OfficeRequest::VOTING) {
            Flux::toast(variant: 'success', text: __('Voting closed: :status.', ['status' => $request->fresh()?->statusLabel()]));
        }
    }

    #[Computed]
    public function requests()
    {
        $user = auth()->user();

        return OfficeRequest::with(['user:id,name,team_name', 'bot:id,name'])
            ->when($this->tab === 'voting', fn ($q) => $q->where('status', OfficeRequest::VOTING)->orderByDesc('votes_up')->orderBy('id'))
            ->when($this->tab === 'mine', fn ($q) => $q->where('user_id', $user?->id ?? 0)->latest('id'))
            ->when($this->tab === 'decided', fn ($q) => $q->where('type', OfficeRequest::BOT_RENAME)
                ->whereNotIn('status', [OfficeRequest::VOTING])->latest('updated_at'))
            ->paginate(10);
    }

    /** @return array<int, int> */
    #[Computed]
    public function myVotes(): array
    {
        return Office::votesOf(auth()->user(), $this->requests->getCollection()->pluck('id')->all());
    }

    #[Computed]
    public function counts(): array
    {
        return [
            'voting' => OfficeRequest::where('status', OfficeRequest::VOTING)->count(),
            'mine' => auth()->id() ? OfficeRequest::where('user_id', auth()->id())->count() : 0,
        ];
    }
}; ?>

@php
    $canVote = \App\Support\Office::canVote(auth()->user());
    $me = auth()->id();
    $tabs = ['voting' => [__('Players vote'), 'hand-thumb-up'], 'mine' => [__('My requests'), 'inbox'], 'decided' => [__('Decided bot names'), 'check-badge']];
    $hints = [
        'ban_appeal' => __('Explain why the ban should be lifted. The administrator will read it and decide.'),
        'inactive_appeal' => __('Your team lost its place after 5 matchdays without a tip. Ask to come back and the administrator will decide.'),
        'bot_rename' => __('Pick a bot and suggest a new name. Players vote first: after :votes votes, a request with at least :approval% thumbs up goes to the administrator.', ['votes' => \App\Support\Office::VOTES_NEEDED, 'approval' => \App\Support\Office::APPROVAL]),
        'other' => __('Anything else you want to tell the administrator: a complaint, an idea or a request.'),
    ];
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <x-page-banner :eyebrow="__('Contact the administrator')" :title="__('Complaints and requests office')"
        :subtitle="__('Appeal a ban or the Inactive status, ask for a new bot name (premium) or send any other request. Bot names are voted on by the players first.')" />

    <div class="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[22rem_minmax(0,1fr)]">
        {{-- Nowa sprawa --}}
        <section class="h-fit space-y-4 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" class="flex items-center gap-2"><flux:icon.pencil-square variant="mini" class="text-lech-700" />{{ __('New request') }}</flux:heading>

            @guest
                <flux:text>{{ __('Log in to send a request or vote.') }}</flux:text>
                <flux:button size="sm" variant="primary" :href="route('login')">{{ __('Log in') }}</flux:button>
            @else
                <form wire:submit="send" class="space-y-4">
                    <flux:radio.group wire:model.live="type" :label="__('What is it about?')">
                        @foreach ($this->types as $value => $label)
                            <flux:radio :value="$value" :label="$label" />
                        @endforeach
                    </flux:radio.group>
                    <flux:error name="type" />

                    @if (! array_key_exists(\App\Models\OfficeRequest::BOT_RENAME, $this->types))
                        <p class="flex items-start gap-2 rounded-lg bg-violet-50 px-3 py-2 text-xs text-violet-800 dark:bg-violet-500/10 dark:text-violet-200">
                            <flux:icon.sparkles variant="micro" class="mt-0.5 shrink-0" />
                            <span>{{ __('Bot rename requests are available with premium.') }} <a href="{{ route('support') }}" wire:navigate class="font-semibold underline">{{ __('See premium') }}</a></span>
                        </p>
                    @endif

                    @if (isset($hints[$type]))
                        <flux:text class="text-xs">{{ $hints[$type] }}</flux:text>
                    @endif

                    @if ($type === \App\Models\OfficeRequest::BOT_RENAME)
                        <div class="space-y-2">
                            @if ($this->selectedBot)
                                <div class="flex items-center justify-between gap-2 rounded-lg border border-lech-200 bg-lech-50 px-3 py-2 text-sm dark:border-lech-800 dark:bg-lech-900/40">
                                    <span class="min-w-0"><span class="text-xs text-zinc-500">{{ __('Bot') }}:</span> <span class="font-semibold italic">{{ $this->selectedBot->name }}</span></span>
                                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="$set('botId', null)" />
                                </div>
                            @else
                                <flux:input wire:model.live.debounce.300ms="botSearch" icon="magnifying-glass" :label="__('Bot')" :placeholder="__('Search bot (min. 2 letters)')" />
                                @if ($this->botOptions->isNotEmpty())
                                    <div class="divide-y divide-zinc-100 overflow-hidden rounded-lg border border-zinc-200 dark:divide-white/5 dark:border-zinc-700">
                                        @foreach ($this->botOptions as $bot)
                                            <button type="button" wire:click="pickBot({{ $bot->id }})" wire:key="bot-option-{{ $bot->id }}"
                                                class="flex w-full items-center justify-between px-3 py-1.5 text-left text-sm italic hover:bg-lech-50 dark:hover:bg-white/5">
                                                {{ $bot->name }}
                                                @unless ($bot->is_active)<span class="text-[10px] not-italic text-zinc-400">{{ __('Resting') }}</span>@endunless
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            @endif
                            <flux:error name="botId" />
                            <flux:input wire:model="botName" :label="__('New bot name')" maxlength="40" />
                        </div>
                    @endif

                    <flux:textarea wire:model="body" :label="__('Justification')" rows="5" maxlength="{{ \App\Support\Office::BODY_MAX }}" />

                    <flux:button type="submit" variant="primary" icon="paper-airplane" class="w-full">{{ __('Send') }}</flux:button>
                </form>
            @endguest
        </section>

        {{-- Listy --}}
        <section class="min-w-0 space-y-4">
            <div class="flex flex-wrap gap-1.5">
                @foreach ($tabs as $value => [$label, $icon])
                    @if ($value !== 'mine' || $me)
                        <button type="button" wire:click="$set('tab', '{{ $value }}')"
                            class="{{ $tab === $value ? 'border-lech-700 bg-lech-700 text-white shadow' : 'border-zinc-200 bg-white text-zinc-700 hover:border-lech-400 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200' }} inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-semibold transition">
                            <flux:icon :name="$icon" variant="micro" />{{ $label }}
                            @if (($this->counts[$value] ?? 0) > 0)
                                <span class="rounded-full bg-white/20 px-1.5 text-xs tabular-nums {{ $tab === $value ? '' : 'bg-zinc-100 dark:bg-white/10' }}">{{ $this->counts[$value] }}</span>
                            @endif
                        </button>
                    @endif
                @endforeach
            </div>

            @forelse ($this->requests as $request)
                @php
                    $rename = $request->type === \App\Models\OfficeRequest::BOT_RENAME;
                    $myVote = $this->myVotes[$request->id] ?? 0;
                    $progress = min(100, round($request->votes() / \App\Support\Office::VOTES_NEEDED * 100));
                    $own = $request->user_id === $me;
                @endphp
                <article wire:key="office-{{ $request->id }}" class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <header class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-100 px-4 py-2 dark:border-white/5">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">{{ $request->typeLabel() }} · {{ $request->created_at?->format('d.m.Y') }}</span>
                        <flux:badge size="sm" :color="$request->statusColor()">{{ $request->statusLabel() }}</flux:badge>
                    </header>
                    <div class="space-y-3 p-4">
                        @if ($rename)
                            <div class="flex flex-wrap items-center gap-2 text-lg">
                                <span class="italic text-zinc-500 line-through decoration-zinc-400">{{ $request->old_bot_name ?? $request->bot?->name }}</span>
                                <flux:icon.arrow-long-right class="size-5 text-lech-600" />
                                <span class="font-bold italic text-lech-800 dark:text-lech-200">{{ $request->bot_name }}</span>
                            </div>
                        @endif
                        <p class="whitespace-pre-line text-sm text-zinc-700 dark:text-zinc-300">{{ $request->body }}</p>
                        @if ($rename)
                            <p class="text-xs text-zinc-500">{{ __('Proposed by :team', ['team' => $request->user?->team_name ?: $request->user?->name]) }}</p>
                        @endif

                        @if ($rename && $request->status === \App\Models\OfficeRequest::VOTING)
                            <div class="flex flex-wrap items-center gap-3">
                                <div class="flex items-center gap-1.5">
                                    @foreach ([1 => ['hand-thumb-up', 'green', $request->votes_up], -1 => ['hand-thumb-down', 'red', $request->votes_down]] as $value => [$icon, $color, $count])
                                        <button type="button" wire:click="vote({{ $request->id }}, {{ $value }})" @disabled(! $canVote || $own)
                                            title="{{ $own ? __('You cannot vote on your own request') : ($canVote ? '' : __('Log in to vote')) }}"
                                            class="{{ $myVote === $value ? ($color === 'green' ? 'border-green-600 bg-green-600 text-white' : 'border-red-600 bg-red-600 text-white') : 'border-zinc-200 text-zinc-600 hover:border-zinc-400 dark:border-zinc-700 dark:text-zinc-300' }} inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm font-semibold tabular-nums transition disabled:cursor-not-allowed disabled:opacity-50">
                                            <flux:icon :name="$icon" variant="micro" />{{ $count }}
                                        </button>
                                    @endforeach
                                </div>
                                <div class="min-w-40 flex-1">
                                    <div class="flex justify-between text-[11px] text-zinc-500">
                                        <span>{{ __(':votes of :needed votes', ['votes' => $request->votes(), 'needed' => \App\Support\Office::VOTES_NEEDED]) }}</span>
                                        <span>{{ __(':percent% for', ['percent' => $request->approval()]) }}</span>
                                    </div>
                                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                                        <div class="h-full rounded-full bg-gradient-to-r from-lech-400 to-lech-700" style="width: {{ $progress }}%"></div>
                                    </div>
                                </div>
                            </div>
                        @elseif ($rename && $request->votes() > 0)
                            <p class="text-xs text-zinc-500">{{ __('Vote: :up for, :down against (:percent%)', ['up' => $request->votes_up, 'down' => $request->votes_down, 'percent' => $request->approval()]) }}</p>
                        @endif

                        @if ($request->admin_reply && ($own || $rename))
                            <div class="rounded-lg border-l-4 border-lech-600 bg-lech-50 px-3 py-2 text-sm dark:bg-lech-900/40">
                                <span class="block text-[11px] font-semibold uppercase tracking-wider text-lech-700 dark:text-lech-300">{{ __('Administrator reply') }}</span>
                                {{ $request->admin_reply }}
                            </div>
                        @endif
                    </div>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-zinc-300 px-4 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700">
                    {{ match ($tab) { 'voting' => __('No bot names are being voted on right now.'), 'mine' => __('You have not sent any requests yet.'), default => __('No decided bot name requests yet.') } }}
                </div>
            @endforelse

            <flux:pagination :paginator="$this->requests" />
        </section>
    </div>
</div>
