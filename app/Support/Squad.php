<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\DesignatedPlayer;
use App\Models\LechPlayer;
use App\Models\Matchday;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Kadra klubu i desygnowanie zawodników (notatka desygnowanie.md). Każdy klub (gracz albo bot) ma całą kadrę Lecha
 * bez niedostępnych, ale dorobek zawodnika liczy się osobno w każdym klubie: tylko z kolejek, w których klub go wystawił.
 *
 * Odpoczynek (R6): zawodnik wystawiony w kolejce odpoczywa w tym klubie przez godziny z raportu meczu (MatchReport, `rest_hours`)
 * liczone od gwizdka tej kolejki, we wszystkich typach rozgrywek. Klub to klucz 'u{id}' albo 'b{id}'.
 */
class Squad
{
    /** @var array<int, array<int, array<string, mixed>>> matchday_id => rozliczenie meczu (MatchReport) */
    private static array $reports = [];

    /**
     * Zawodnicy do wyboru: kadra bez niedostępnych, według pozycji i numeru.
     *
     * @return Collection<int, LechPlayer>
     */
    public static function available(): Collection
    {
        $order = array_flip(PlayerPosition::values());

        return LechPlayer::where('status', '!=', PlayerStatus::Excluded->value)->get()
            ->sortBy(fn (LechPlayer $p) => [$order[$p->position->value], $p->number ?? 999, $p->name])
            ->values();
    }

    public static function clubKey(?int $userId, ?int $botId): string
    {
        return $userId !== null ? 'u'.$userId : 'b'.$botId;
    }

    /**
     * Odpoczynek klubu przed kolejką: lech_player_id => chwila (timestamp), od której zawodnik znów jest do wyboru.
     * Zawiera tylko zawodników, którzy w chwili gwizdka tej kolejki jeszcze odpoczywają.
     *
     * @return array<int, int>
     */
    public static function locks(?int $userId, ?int $botId, Matchday $matchday): array
    {
        return self::locksForClubs($matchday, $userId !== null ? [$userId] : [], $botId !== null ? [$botId] : [])[self::clubKey($userId, $botId)] ?? [];
    }

    /**
     * Odpoczynek wielu klubów naraz (np. wszystkich botów przy przeliczeniu kolejki). R6: zawodnik wystawiony w kolejce
     * odpoczywa w tym klubie przez godziny z raportu meczu (`rest_hours`), liczone od gwizdka tej kolejki (koniec typowania),
     * we wszystkich typach rozgrywek i bez względu na sezon. Wybór, który przepadł przez odpoczynek, nie przedłuża go.
     *
     * @param  array<int, int>  $userIds
     * @param  array<int, int>  $botIds
     * @return array<string, array<int, int>> klucz klubu => [lech_player_id => wolny od (timestamp)]
     */
    public static function locksForClubs(Matchday $matchday, array $userIds, array $botIds): array
    {
        if ($userIds === [] && $botIds === []) {
            return [];
        }

        $kickoff = $matchday->kickoff_at ?? now();
        $longest = self::restHoursFor(4, 0);

        $previous = Matchday::where('id', '!=', $matchday->id)
            ->whereNotNull('kickoff_at')
            ->where('kickoff_at', '<', $kickoff)
            ->where('kickoff_at', '>=', $kickoff->copy()->subHours($longest))
            ->get(['id', 'season_id', 'number', 'kickoff_at', 'lech_match_id'])
            ->keyBy('id');

        if ($previous->isEmpty()) {
            return [];
        }

        $picks = DesignatedPlayer::whereIn('matchday_id', $previous->keys())
            ->where(fn ($q) => $q->whereIn('user_id', $userIds)->orWhereIn('bot_id', $botIds))
            ->where(fn ($q) => $q->whereNull('counted')->orWhere('counted', true))
            ->get(['matchday_id', 'user_id', 'bot_id', 'lech_player_id']);

        $locks = [];

        foreach ($picks as $pick) {
            $played = $previous[$pick->matchday_id];
            $hours = (int) (self::report($played)[$pick->lech_player_id]['rest_hours'] ?? 0);
            $freeAt = $played->kickoff_at->getTimestamp() + $hours * 3600;

            if ($hours > 0 && $freeAt > $kickoff->getTimestamp()) {
                $club = self::clubKey($pick->user_id, $pick->bot_id);
                $locks[$club][$pick->lech_player_id] = max($locks[$club][$pick->lech_player_id] ?? 0, $freeAt);
            }
        }

        return $locks;
    }

    /** Godziny odpoczynku za całe i niecałe połowy według bieżących ustawień. */
    public static function restHoursFor(int $full, int $partial): int
    {
        return MatchReport::restHours($full, $partial, Designation::values());
    }

    /** Ile pełnych godzin (w górę) zostało do chwili, od której zawodnik jest znów do wyboru. */
    public static function hoursLeft(int $freeAt): int
    {
        return max(0, (int) ceil(($freeAt - now()->getTimestamp()) / 3600));
    }

    /**
     * Rozliczenie meczu kolejki z raportu meczu Lecha (pamięć podręczna na czas żądania).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function report(Matchday $matchday): array
    {
        return self::$reports[$matchday->id] ??= MatchReport::forMatch($matchday->lech_match_id);
    }

    /** Po zmianie raportu meczu albo w testach. */
    public static function forget(): void
    {
        self::$reports = [];
    }

    /**
     * Wybory klubu w kolejce: typ rozgrywek (wartość) => lista id zawodników.
     *
     * @return array<string, array<int, int>>
     */
    public static function picks(?int $userId, ?int $botId, Matchday $matchday): array
    {
        return DesignatedPlayer::where('matchday_id', $matchday->id)
            ->where($userId !== null ? 'user_id' : 'bot_id', $userId ?? $botId)
            ->orderBy('id')
            ->get(['score_set', 'lech_player_id'])
            ->groupBy(fn (DesignatedPlayer $p) => $p->score_set->value)
            ->map(fn ($group) => $group->pluck('lech_player_id')->all())
            ->all();
    }

    /**
     * Gracz wystawia zawodnika w typie rozgrywek, przenosi go z innego typu albo zdejmuje (klik w tym samym typie).
     * Zasady: typowanie otwarte, gracz gra w tym typie rozgrywek, zawodnik dostępny i nie odpoczywa,
     * w kolejce tylko w jednym typie (przeniesienie zmienia typ), najwyżej max_players w typie.
     *
     * @return string 'added' | 'moved' | 'removed'
     *
     * @throws DomainException
     */
    public static function toggle(User $user, Matchday $matchday, CompetitionType $set, int $playerId): string
    {
        if (! $matchday->isOpenForTips() || ! $matchday->isFilled()) {
            throw new DomainException(__('The line-up can be changed only until kickoff.'));
        }

        if (! Players::canPlay($user)) {
            throw new DomainException(__('You cannot play now.'));
        }

        $existing = DesignatedPlayer::where('matchday_id', $matchday->id)->where('user_id', $user->id)
            ->where('lech_player_id', $playerId)->first();

        if ($existing && $existing->score_set === $set) {
            $existing->delete();

            return 'removed';
        }

        if (! in_array($set, PlayerCompetitions::types($user->id, $matchday->season_id, $matchday->number), true)) {
            throw new DomainException(__('You do not play in these competitions.'));
        }

        if (! self::available()->contains('id', $playerId)) {
            throw new DomainException(__('This player is unavailable.'));
        }

        if ($freeAt = self::locks($user->id, null, $matchday)[$playerId] ?? 0) {
            throw new DomainException(__('This player rests until :date.', ['date' => Carbon::createFromTimestamp($freeAt, config('app.timezone'))->format('d.m, H:i')]));
        }

        $max = Designation::value('max_players');
        $count = DesignatedPlayer::where('matchday_id', $matchday->id)->where('user_id', $user->id)->where('score_set', $set->value)->count();

        if ($count >= $max) {
            throw new DomainException(__('You can field at most :count players here.', ['count' => $max]));
        }

        if ($existing) {
            $existing->update(['score_set' => $set->value]);

            return 'moved';
        }

        DesignatedPlayer::create([
            'matchday_id' => $matchday->id,
            'user_id' => $user->id,
            'score_set' => $set->value,
            'lech_player_id' => $playerId,
        ]);

        return 'added';
    }

    /**
     * Boty wystawiają w każdym swoim typie rozgrywek od bot_players_min do bot_players_max dostępnych zawodników
     * bez blokady (R7). Losowanie jest stałe dla kolejki i bota, więc ponowne przeliczenie daje ten sam skład.
     *
     * @param  array<int, array<int, string>>  $setsOfBot  bot_id => typy rozgrywek (wartości), w których gra
     */
    public static function pickForBots(Matchday $matchday, array $setsOfBot): void
    {
        DesignatedPlayer::where('matchday_id', $matchday->id)->whereNotNull('bot_id')->delete();

        if ($setsOfBot === []) {
            return;
        }

        $squad = self::available()->pluck('id')->all();
        $locks = self::locksForClubs($matchday, [], array_keys($setsOfBot));
        $min = max(0, Designation::value('bot_players_min'));
        $max = max($min, Designation::value('bot_players_max'));
        $now = now();
        $rows = [];

        foreach ($setsOfBot as $botId => $sets) {
            $random = new Randomizer(new Mt19937(crc32($matchday->id.'-'.$botId)));
            $free = array_values(array_diff($squad, array_keys($locks[self::clubKey(null, $botId)] ?? [])));
            $free = $free === [] ? [] : $random->shuffleArray($free);

            foreach ($sets as $set) {
                $count = min(count($free), $random->getInt($min, $max));

                foreach (array_splice($free, 0, $count) as $playerId) {
                    $rows[] = [
                        'matchday_id' => $matchday->id,
                        'bot_id' => $botId,
                        'score_set' => $set,
                        'lech_player_id' => $playerId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DesignatedPlayer::insert($chunk);
        }
    }

    /**
     * Rozliczenie wystawionych zawodników kolejki (przy przeliczeniu, ScoreMatchday): zapisuje dorobek każdego wyboru
     * i zwraca sumy klubu w typie rozgrywek. Wybór zawodnika z blokadą przepada (counted = false).
     * Doświadczenie do remisów = doświadczenie wystawionych zawodników zebrane w klubie (cała kariera) z tym meczem.
     *
     * @return array<string, array<string, array{attack: int, defense: int, own_goals: int, experience: float, yellow: int, red: int}>>
     */
    public static function settle(Matchday $matchday): array
    {
        self::forget();
        $report = self::report($matchday);
        $picks = DesignatedPlayer::with('player:id,position')->where('matchday_id', $matchday->id)->get();

        if ($picks->isEmpty()) {
            return [];
        }

        $userIds = $picks->pluck('user_id')->filter()->unique()->values()->all();
        $botIds = $picks->pluck('bot_id')->filter()->unique()->values()->all();
        $locks = self::locksForClubs($matchday, $userIds, $botIds);
        $before = self::experienceBefore($matchday, $userIds, $botIds);
        $cleanGk = Designation::value('clean_sheet_goalkeeper');
        $cleanDf = Designation::value('clean_sheet_defender');
        $totals = [];

        foreach ($picks as $pick) {
            $club = self::clubKey($pick->user_id, $pick->bot_id);
            $locked = isset($locks[$club][$pick->lech_player_id]);
            $r = $report[$pick->lech_player_id] ?? null;
            // W kadrze meczowej (jedenastka albo ławka): doświadczenie za powołanie i kartki; zagrał: reszta dorobku.
            $inSquad = ! $locked && $r !== null;
            $played = $inSquad && $r['played'];
            $goalkeeper = $pick->player?->position === PlayerPosition::Goalkeeper;

            $values = [
                'counted' => ! $locked,
                'play_time' => $played ? (float) $r['play_time'] : 0,
                'goals' => $played ? (int) $r['goals'] : 0,
                'assists' => $played ? (int) $r['assists'] : 0,
                'own_goals' => $played ? (int) $r['own_goals'] : 0,
                'clean_sheet' => $played ? $r['clean_sheet'] : null,
                'yellow' => $inSquad ? (int) $r['yellow'] : 0,
                'red' => $inSquad && $r['red'],
                'attack' => $played ? (int) $r['goals'] + (int) $r['assists'] : 0,
                'defense' => $played && $r['clean_sheet'] === true ? ($goalkeeper ? $cleanGk : $cleanDf) : 0,
                // Wystawiony przez klub, a nieobecny w kadrze meczowej Lecha: samo wystawienie daje premię jak ławka (0,5).
                'experience' => $inSquad ? (float) $r['experience'] : ($locked ? 0 : Designation::number('exp_sub')),
                'rest_hours' => $played ? (int) $r['rest_hours'] : 0,
            ];
            $pick->update($values);

            $set = $pick->score_set->value;
            $total = $totals[$club][$set] ?? ['attack' => 0, 'defense' => 0, 'own_goals' => 0, 'experience' => 0.0, 'yellow' => 0, 'red' => 0];

            if (! $locked) {
                $total['attack'] += $values['attack'];
                $total['defense'] += $values['defense'];
                $total['own_goals'] += $values['own_goals'];
                $total['experience'] += ($before[$club][$pick->lech_player_id] ?? 0) + $values['experience'];
                $total['yellow'] += $values['yellow'];
                $total['red'] += (int) $values['red'];
            }
            $totals[$club][$set] = $total;
        }

        return $totals;
    }

    /**
     * Ile występów daje wybór w typie rozgrywek: Liga Legend ma w kolejce dwa pojedynki, więc wszystko liczy się podwójnie
     * (doświadczenie, statystyki zawodnika w klubie).
     */
    public static function appearances(CompetitionType|string $set): int
    {
        $value = $set instanceof CompetitionType ? $set->value : $set;

        return $value === CompetitionType::Legends->value ? 2 : 1;
    }

    /** SQL: doświadczenie wyboru z mnożnikiem występów (Liga Legend × 2). */
    private static function experienceSql(): string
    {
        return "SUM(designated_players.experience * CASE WHEN designated_players.score_set = '".CompetitionType::Legends->value."' THEN 2 ELSE 1 END)";
    }

    /**
     * Doświadczenie zawodników w klubie (cała kariera, rozliczone kolejki): do kolejności i podglądu w wyborze składu.
     *
     * @return array<int, float> lech_player_id => doświadczenie
     */
    public static function clubExperience(?int $userId, ?int $botId): array
    {
        if ($userId === null && $botId === null) {
            return [];
        }

        return DesignatedPlayer::query()
            ->where('designated_players.counted', true)
            ->where($userId !== null ? 'user_id' : 'bot_id', $userId ?? $botId)
            ->groupBy('lech_player_id')
            ->selectRaw('lech_player_id, '.self::experienceSql().' as total')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->lech_player_id => round((float) $row->getAttribute('total'), 1)])
            ->all();
    }

    /**
     * Doświadczenie zawodników zebrane w klubach przed tą kolejką (wszystkie sezony, mecze z wcześniejszym gwizdkiem).
     *
     * @param  array<int, int>  $userIds
     * @param  array<int, int>  $botIds
     * @return array<string, array<int, float>> klucz klubu => [lech_player_id => doświadczenie]
     */
    public static function experienceBefore(Matchday $matchday, array $userIds, array $botIds): array
    {
        $rows = DesignatedPlayer::query()
            ->join('matchdays', 'matchdays.id', '=', 'designated_players.matchday_id')
            ->where('designated_players.counted', true)
            ->where('designated_players.matchday_id', '!=', $matchday->id)
            ->where('matchdays.kickoff_at', '<', $matchday->kickoff_at ?? now())
            ->where(fn ($q) => $q->whereIn('designated_players.user_id', $userIds)->orWhereIn('designated_players.bot_id', $botIds))
            ->groupBy('designated_players.user_id', 'designated_players.bot_id', 'designated_players.lech_player_id')
            ->selectRaw('designated_players.user_id, designated_players.bot_id, designated_players.lech_player_id, '.self::experienceSql().' as total')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[self::clubKey($row->user_id, $row->bot_id)][(int) $row->lech_player_id] = (float) $row->getAttribute('total');
        }

        return $out;
    }

    /** Kolor odpoczynku według pozostałych godzin: do 36 jasnożółty, do 72 żółty, do 144 pomarańczowy, do 216 czerwony, dalej bordowy. */
    public static function lockColor(int $hours): string
    {
        return match (true) {
            $hours < 36 => 'bg-yellow-200 text-zinc-900',
            $hours < 72 => 'bg-yellow-400 text-zinc-900',
            $hours < 144 => 'bg-orange-500 text-white',
            $hours < 216 => 'bg-red-600 text-white',
            default => 'bg-red-900 text-white',
        };
    }

    /** Progi kolorów odpoczynku (legenda): górna granica w godzinach => przykładowa wartość do koloru. */
    public const REST_LEGEND = [36 => 20, 72 => 50, 144 => 100, 216 => 180, 288 => 250];
}
