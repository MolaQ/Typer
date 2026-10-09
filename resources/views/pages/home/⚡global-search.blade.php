<?php

use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\Matchday;
use App\Models\News;
use App\Models\Season;
use App\Models\User;
use App\Support\Guide;
use App\Support\Players;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Globalna wyszukiwarka w nagłówku (dla wszystkich, także gości): zespoły i gracze, rozgrywki bieżącego sezonu,
 * kolejki (rywal Lecha), punkty regulaminu i FAQ, newsy oraz strony serwisu. Wyniki na żywo od 2 znaków.
 */
new class extends Component {
    public string $q = '';

    /** Ile wyników pokazujemy w jednej grupie. */
    private const LIMIT = 5;

    /** @return array<int, array{label: string, icon: string, items: array<int, array{title: string, sub: ?string, url: string}>}> */
    #[Computed]
    public function groups(): array
    {
        $q = trim($this->q);

        if (mb_strlen($q) < 2) {
            return [];
        }

        $groups = [
            [__('Teams and players'), 'users', $this->teams($q)],
            [__('Competitions'), 'trophy', $this->competitions($q)],
            [__('Matchdays'), 'calendar', $this->matchdays($q)],
            [__('Rules of the game'), 'book-open', $this->guide(Guide::rules(), $q, route('rules'), 'rule')],
            [__('FAQ'), 'question-mark-circle', $this->guide(Guide::faq(), $q, route('faq'), 'faq')],
            [__('News'), 'newspaper', $this->news($q)],
            [__('Pages'), 'document-text', $this->pages($q)],
        ];

        return collect($groups)
            ->filter(fn ($g) => count($g[2]) > 0)
            ->map(fn ($g) => ['label' => $g[0], 'icon' => $g[1], 'items' => $g[2]])
            ->values()
            ->all();
    }

    private function like(string $q): string
    {
        return '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    }

    private function teams(string $q): array
    {
        $like = $this->like($q);

        return User::query()
            ->whereHas('roles')
            ->whereDoesntHave('roles', fn ($r) => $r->whereIn('name', Players::BLOCKING_ROLES))
            ->where(fn ($w) => $w->where('team_name', 'like', $like)
                ->orWhere('team_short_name', 'like', $like)
                ->orWhere('team_abbr', 'like', $like)
                ->orWhere('name', 'like', $like))
            ->orderByRaw('team_name is null')
            ->orderBy('team_name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'team_name', 'team_abbr'])
            ->map(fn (User $u) => [
                'title' => $u->team_name ?: $u->name,
                'sub' => $u->team_name ? $u->name : null,
                'url' => route('team.show', $u),
            ])
            ->all();
    }

    private function competitions(string $q): array
    {
        $season = Season::current();

        if (!$season) {
            return [];
        }

        return Competition::where('season_id', $season->id)
            ->where('name', 'like', $this->like($q))
            ->orderBy('tier')->orderBy('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Competition $c) => [
                'title' => $c->name,
                'sub' => $season->title,
                // Klucze jak na stronie wyników (league-N, cup, swiss, c-ID).
                'url' => route('results', ['c' => match ($c->type) {
                    CompetitionType::League => 'league-' . $c->tier,
                    CompetitionType::Cup => 'cup',
                    CompetitionType::Swiss => 'swiss',
                    default => 'c-' . $c->id,
                }]),
            ])
            ->all();
    }

    private function matchdays(string $q): array
    {
        $season = Season::current();

        if (!$season) {
            return [];
        }

        // Rozgrywki meczu są zapisane jako wartość enuma, więc szukamy po przetłumaczonych etykietach.
        $competitions = collect(\App\Enums\MatchCompetition::options())
            ->filter(fn ($label) => mb_stripos($label, $q) !== false)
            ->keys()
            ->all();

        return Matchday::where('season_id', $season->id)
            ->where(fn ($w) => $w->where('opponent', 'like', $this->like($q))
                ->orWhere('competition', 'like', $this->like($q))
                ->orWhereIn('competition', $competitions))
            ->orderBy('number')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Matchday $m) => [
                'title' => $m->fixture,
                'sub' => __('Matchday :number', ['number' => $m->number])
                    . ($m->kickoff_at ? ' · ' . $m->kickoff_at->format('d.m.Y H:i') : ''),
                'url' => route('results', ['round' => $m->number]),
            ])
            ->all();
    }

    /** Punkty regulaminu albo FAQ: szukamy w tytule i treści, link otwiera właściwą pozycję akordeonu. */
    private function guide(array $entries, string $q, string $url, string $anchor): array
    {
        $found = [];

        foreach ($entries as $index => [$title, $body]) {
            $text = strip_tags($body);
            if (mb_stripos($title, $q) === false && mb_stripos($text, $q) === false) {
                continue;
            }

            $found[] = ['title' => $title, 'sub' => $this->snippet($text, $q), 'url' => $url . '#' . $anchor . '-' . $index];

            if (count($found) >= self::LIMIT) {
                break;
            }
        }

        return $found;
    }

    private function news(string $q): array
    {
        if (!Schema::hasTable('news')) {
            return [];
        }

        $like = $this->like($q);

        return News::published()
            ->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('body', 'like', $like))
            ->latest('published_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (News $n) => [
                'title' => $n->title,
                'sub' => $n->published_at?->format('d.m.Y'),
                'url' => route('news.show', $n),
            ])
            ->all();
    }

    private function pages(string $q): array
    {
        $pages = [
            [__('Home'), route('home')],
            [__('Results and tables'), route('results')],
            [__('Hall of Fame'), route('hall-of-fame')],
            [__('Premium and support'), route('support')],
            [__('Rules of the game'), route('rules')],
            [__('FAQ'), route('faq')],
            [__('System information'), route('system')],
        ];

        if (auth()->check() && Players::canPlay(auth()->user())) {
            $pages[] = ['LechTYPER', route('tips')];
        }

        return collect($pages)
            ->filter(fn ($p) => mb_stripos($p[0], $q) !== false)
            ->map(fn ($p) => ['title' => $p[0], 'sub' => null, 'url' => $p[1]])
            ->values()
            ->all();
    }

    /** Fragment tekstu wokół trafienia. */
    private function snippet(string $text, string $q): string
    {
        $pos = mb_stripos($text, $q);
        if ($pos === false) {
            return mb_strimwidth($text, 0, 80, '…');
        }

        $start = max(0, $pos - 30);

        return ($start > 0 ? '…' : '') . mb_strimwidth(mb_substr($text, $start), 0, 90, '…');
    }
}; ?>

<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
    <flux:input wire:model.live.debounce.250ms="q" size="sm" icon="magnifying-glass" clearable
        :placeholder="__('Search…')" class="w-36 sm:w-56 lg:w-72" x-on:focus="open = true" x-on:input="open = true"
        :aria-label="__('Search')" />

    @if (mb_strlen(trim($q)) >= 2)
        <div x-show="open" x-cloak x-transition.opacity
            class="absolute end-0 z-50 mt-2 max-h-[70vh] w-[min(24rem,calc(100vw-2rem))] overflow-y-auto rounded-xl border border-zinc-200 bg-white p-2 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
            @forelse ($this->groups as $group)
                <div class="py-1" wire:key="gs-{{ $loop->index }}">
                    <div class="flex items-center gap-1.5 px-2 pb-1 text-[11px] font-semibold uppercase tracking-wide text-lech-700 dark:text-lech-300">
                        <flux:icon :name="$group['icon']" variant="micro" />
                        {{ $group['label'] }}
                    </div>
                    @foreach ($group['items'] as $item)
                        <a href="{{ $item['url'] }}" @click="open = false"
                            class="block rounded-lg px-2 py-1.5 text-sm hover:bg-lech-50 dark:hover:bg-lech-950">
                            <span class="block truncate font-medium">{{ $item['title'] }}</span>
                            @if ($item['sub'])
                                <span class="block truncate text-xs text-zinc-500">{{ $item['sub'] }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @empty
                <div class="px-2 py-3 text-sm text-zinc-500">{{ __('Nothing found.') }}</div>
            @endforelse
        </div>
    @endif
</div>
