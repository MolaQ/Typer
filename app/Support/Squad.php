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
use Illuminate\Support\Collection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Kadra klubu i desygnowanie zawodników (notatka desygnowanie.md). Każdy klub (gracz albo bot) ma całą kadrę Lecha
 * bez niedostępnych, ale dorobek zawodnika liczy się osobno w każdym klubie: tylko z kolejek, w których klub go wystawił.
 *
 * Blokada (R6): zawodnik wystawiony w kolejce m, który zagrał L całych połów (MatchReport, `lock`), odpoczywa
 * w tym klubie kolejki m+1 … m+L tego sezonu, we wszystkich typach rozgrywek. Klub to klucz 'u{id}' albo 'b{id}'.
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
     * Blokady klubu w kolejce: lech_player_id => ile kolejek blokady zostało (licząc tę), np. 2 = ta i następna.
     *
     * @return array<int, int>
     */
    public static function locks(?int $userId, ?int $botId, Matchday $matchday): array
    {
        return self::locksForClubs($matchday, $userId !== null ? [$userId] : [], $botId !== null ? [$botId] : [])[self::clubKey($userId, $botId)] ?? [];
    }

    /**
     * Blokady wielu klubów naraz (np. wszystkich botów przy przeliczeniu kolejki).
     *
     * @param  array<int, int>  $userIds
     * @param  array<int, int>  $botIds
     * @return array<string, array<int, int>> klucz klubu => [lech_player_id => kolejki blokady]
     */
    public static function locksForClubs(Matchday $matchday, array $userIds, array $botIds): array
    {
        $previous = Matchday::where('season_id', $matchday->season_id)
            ->where('number', '<', $matchday->number)
            ->get(['id', 'season_id', 'number', 'extra_time'])
            ->keyBy('id');

        if ($previous->isEmpty() || ($userIds === [] && $botIds === [])) {
            return [];
        }

        $picks = DesignatedPlayer::whereIn('matchday_id', $previous->keys())
            ->where(fn ($q) => $q->whereIn('user_id', $userIds)->orWhereIn('bot_id', $botIds))
            ->get(['matchday_id', 'user_id', 'bot_id', 'lech_player_id']);

        $locks = [];

        foreach ($picks as $pick) {
            $played = $previous[$pick->matchday_id];
            $lock = (int) (self::report($played)[$pick->lech_player_id]['lock'] ?? 0);
            $left = $played->number + $lock - $matchday->number + 1;

            if ($lock > 0 && $left > 0) {
                $club = self::clubKey($pick->user_id, $pick->bot_id);
                $locks[$club][$pick->lech_player_id] = max($locks[$club][$pick->lech_player_id] ?? 0, $left);
            }
        }

        return $locks;
    }

    /**
     * Rozliczenie meczu kolejki (pamięć podręczna na czas żądania).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function report(Matchday $matchday): array
    {
        return self::$reports[$matchday->id] ??= MatchReport::forMatchday($matchday);
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
     * Gracz wystawia zawodnika w typie rozgrywek albo go zdejmuje (drugie kliknięcie). Zasady: typowanie otwarte,
     * gracz gra w tym typie rozgrywek, zawodnik dostępny i bez blokady, w kolejce tylko w jednym typie, najwyżej max_players.
     *
     * @return bool true = wystawiony, false = zdjęty
     *
     * @throws DomainException
     */
    public static function toggle(User $user, Matchday $matchday, CompetitionType $set, int $playerId): bool
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

            return false;
        }

        if ($existing) {
            throw new DomainException(__('This player is already in your line-up for :set.', ['set' => $existing->score_set->scoreSetLabel()]));
        }

        if (! in_array($set, PlayerCompetitions::types($user->id, $matchday->season_id, $matchday->number), true)) {
            throw new DomainException(__('You do not play in these competitions.'));
        }

        if (! self::available()->contains('id', $playerId)) {
            throw new DomainException(__('This player is unavailable.'));
        }

        if ($left = self::locks($user->id, null, $matchday)[$playerId] ?? 0) {
            throw new DomainException(trans_choice('This player rests for :count more matchday.|This player rests for :count more matchdays.', $left, ['count' => $left]));
        }

        $max = Designation::value('max_players');
        $count = DesignatedPlayer::where('matchday_id', $matchday->id)->where('user_id', $user->id)->where('score_set', $set->value)->count();

        if ($count >= $max) {
            throw new DomainException(__('You can field at most :count players here.', ['count' => $max]));
        }

        DesignatedPlayer::create([
            'matchday_id' => $matchday->id,
            'user_id' => $user->id,
            'score_set' => $set->value,
            'lech_player_id' => $playerId,
        ]);

        return true;
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

    /** Kolor blokady według liczby pozostałych kolejek: 4+ bordowy, 3 czerwony, 2 pomarańczowy, 1 żółty. */
    public static function lockColor(int $left): string
    {
        return match (true) {
            $left >= 4 => 'bg-red-900 text-white',
            $left === 3 => 'bg-red-600 text-white',
            $left === 2 => 'bg-orange-500 text-white',
            default => 'bg-yellow-400 text-zinc-900',
        };
    }
}
