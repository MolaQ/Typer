<?php

namespace App\Support;

use App\Enums\KnockoutStage;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Fixture;
use Illuminate\Support\Collection;

/**
 * Format Ligi Mistrzów, Ligi Europy i Ligi Konferencji: 24 zespoły, 9 kolejek.
 *
 * Faza grupowa (kolejki 1-5): 4 grupy (A, B, C, D) po 6 zespołów, każdy z każdym raz, 3 mecze grupy
 * w każdej kolejce. Grupa i miejsce w niej wynikają z rozstawienia (koszyki po 4): rozstawienie 1-4 to
 * pierwsze zespoły grup A-D, 5-8 drugie i tak dalej. Punkty i tabela jak w lidze (Standings).
 *
 * Faza pucharowa (kolejki 6-9: 1/8 finału, ćwierćfinał, półfinał, finał): awansują 4 najlepsze z każdej grupy.
 * Zespoły z tego samego miejsca w grupach tworzą wirtualną tabelę (kryteria ligowe), z której wynika numer
 * miejsca w drabince: jedynki 1-4, dwójki 5-8, trójki 9-12, czwórki 13-16 („trzecia dwójka” = miejsce 7).
 * Drabinka jest taka jak w Pucharze Polski (CupBracket): w rundzie z M miejscami gra 1 z M, 2 z M-1 itd.,
 * zwycięzca przejmuje lepsze miejsce pary, a remis rozstrzyga wcześniej zapisany typ. Dzięki temu:
 *   1/8:  1-16, 2-15, 3-14, 4-13, 5-12, 6-11, 7-10, 8-9,
 *   ćwierćfinał: zwycięzca 1-16 ze zwycięzcą 8-9, 4-13 z 5-12, 3-14 z 6-11, 2-15 z 7-10,
 *   półfinał: pierwsza jedynka z czwartą jedynką, druga z trzecią (gdy wygrywają faworyci), a gdy wygrywają
 *   dwójki, trójki albo czwórki, tak samo: pierwsza z czwartą i druga z trzecią,
 *   finał: pierwsza jedynka z drugą jedynką.
 * Miejsca 5 i 6 w grupie odpadają po kolejce 5 (eliminated_round = 5).
 */
final class EuropeanCup
{
    public const GROUPS = ['A', 'B', 'C', 'D'];

    public const GROUP_SIZE = 6;

    public const TEAMS = 24;

    /** Kolejki fazy grupowej (1-5). */
    public const GROUP_ROUNDS = 5;

    /** Tyle zespołów z każdej grupy awansuje do 1/8 finału. */
    public const ADVANCE = 4;

    public const KNOCKOUT_TEAMS = 16;

    public const ROUNDS = 9;

    /** Terminarz grupy (miejsca w grupie 1-6), każdy z każdym raz; mecz 1-2 w ostatniej kolejce. */
    public const GROUP_SCHEDULE = [
        1 => [[1, 6], [2, 5], [3, 4]],
        2 => [[1, 5], [4, 6], [2, 3]],
        3 => [[1, 4], [3, 5], [2, 6]],
        4 => [[1, 3], [2, 4], [5, 6]],
        5 => [[1, 2], [3, 6], [4, 5]],
    ];

    /** Litera grupy dla rozstawienia 1-24 (1 = A, 2 = B, 3 = C, 4 = D, 5 = A ...). */
    public static function groupOf(int $seed): string
    {
        return self::GROUPS[($seed - 1) % count(self::GROUPS)];
    }

    /** Rozstawienie zespołu z danego miejsca (1-6) w grupie (A-D). */
    public static function seedOf(string $group, int $place): int
    {
        return ($place - 1) * count(self::GROUPS) + (int) array_search($group, self::GROUPS, true) + 1;
    }

    public static function isGroupRound(int $round): bool
    {
        return $round >= 1 && $round <= self::GROUP_ROUNDS;
    }

    public static function isKnockoutRound(int $round): bool
    {
        return $round > self::GROUP_ROUNDS && $round <= self::ROUNDS;
    }

    /**
     * Mecze fazy grupowej (rozstawienia 1-24): [['round' => 1, 'home' => 1, 'away' => 21], ...] (60 meczów).
     * Gospodarzem jest zespół z wyższym rozstawieniem.
     *
     * @return array<int, array{round: int, home: int, away: int}>
     */
    public static function groupFixtures(): array
    {
        $list = [];

        foreach (self::GROUP_SCHEDULE as $round => $pairs) {
            foreach (self::GROUPS as $group) {
                foreach ($pairs as [$a, $b]) {
                    $list[] = ['round' => $round, 'home' => self::seedOf($group, $a), 'away' => self::seedOf($group, $b)];
                }
            }
        }

        return $list;
    }

    /** Ile miejsc drabinki gra w rundzie pucharowej: 16, 8, 4, 2. */
    public static function seatsInRound(int $round): int
    {
        return intdiv(self::KNOCKOUT_TEAMS, 2 ** ($round - self::GROUP_ROUNDS - 1));
    }

    /**
     * Mecze fazy pucharowej (miejsca drabinki 1-16): 1/8 finału 1-16, 2-15 ..., potem jak w Pucharze Polski.
     *
     * @return array<int, array{round: int, home: int, away: int}>
     */
    public static function knockoutFixtures(): array
    {
        $list = [];

        for ($round = self::GROUP_ROUNDS + 1; $round <= self::ROUNDS; $round++) {
            $seats = self::seatsInRound($round);
            for ($i = 1; $i <= intdiv($seats, 2); $i++) {
                $list[] = ['round' => $round, 'home' => $i, 'away' => $seats + 1 - $i];
            }
        }

        return $list;
    }

    /** Nazwa etapu: „Faza grupowa – kolejka N”, „1/8 finału”, „Ćwierćfinał”, „Półfinał”, „Finał”. */
    public static function stageLabel(int $round): string
    {
        return match (true) {
            self::isGroupRound($round) => __('Group stage – matchday :number', ['number' => $round]),
            $round === self::GROUP_ROUNDS + 1 => __('Round of 16'),
            default => KnockoutStage::labelFor($round),
        };
    }

    /**
     * Miejsce w drabince opisane miejscem w grupie, np. „trzecia dwójka” (trzeci najlepszy z drugich miejsc).
     */
    public static function seatLabel(int $seat): string
    {
        $rank = ($seat - 1) % count(self::GROUPS) + 1;
        $place = intdiv($seat - 1, count(self::GROUPS)) + 1;

        $ranks = [1 => __('first best'), 2 => __('second best'), 3 => __('third best'), 4 => __('fourth best')];

        return $ranks[$rank].' '.self::placeNoun($place);
    }

    /** Zespół z danego miejsca w grupie: „jedynka”, „dwójka” … „szóstka”. */
    public static function placeNoun(int $place): string
    {
        return match ($place) {
            1 => __('group winner'),
            2 => __('group runner-up'),
            3 => __('group third'),
            4 => __('group fourth'),
            5 => __('group fifth'),
            default => __('group sixth'),
        };
    }

    /**
     * Tabele grup po meczach fazy grupowej: litera => wiersze Standings (od pierwszego miejsca).
     *
     * @return array<string, Collection<int, array<string, mixed>>>
     */
    public static function groupTables(Competition $competition): array
    {
        $seeds = CompetitionEntry::where('competition_id', $competition->id)->pluck('seed', 'id');
        $tables = [];

        foreach (self::GROUPS as $group) {
            $ids = $seeds->filter(fn (int $seed) => self::groupOf($seed) === $group)->keys()->all();
            $tables[$group] = Standings::for($competition, $ids, self::GROUP_ROUNDS);
        }

        return $tables;
    }

    /** Litera grupy wpisu (po jego rozstawieniu). */
    public static function groupOfEntry(CompetitionEntry $entry): string
    {
        return self::groupOf($entry->seed);
    }

    /**
     * Rozstawienie fazy pucharowej z tabel grup: miejsce w drabince (1-16) => id wpisu.
     * Dla każdego miejsca w grupie (1-4) zespoły z czterech grup układamy kryteriami ligowymi.
     *
     * @return array<int, int>
     */
    public static function knockoutSeats(Competition $competition): array
    {
        $tables = self::groupTables($competition);
        $seats = [];

        for ($place = 1; $place <= self::ADVANCE; $place++) {
            $rows = [];
            foreach ($tables as $table) {
                if ($row = $table->values()->get($place - 1)) {
                    $rows[] = $row;
                }
            }

            foreach (Standings::rank($rows)->values() as $index => $row) {
                $seats[($place - 1) * count(self::GROUPS) + $index + 1] = (int) $row['entry_id'];
            }
        }

        return $seats;
    }

    /**
     * Wirtualne tabele miejsc: miejsce w grupie (1-4) => wiersze od najlepszego (do pokazania „jedynek”, „dwójek”...).
     *
     * @return array<int, Collection<int, array<string, mixed>>>
     */
    public static function placeTables(Competition $competition): array
    {
        $tables = self::groupTables($competition);
        $out = [];

        for ($place = 1; $place <= self::GROUP_SIZE; $place++) {
            $rows = [];
            foreach ($tables as $group => $table) {
                if ($row = $table->values()->get($place - 1)) {
                    $rows[] = $row + ['group' => $group];
                }
            }
            $out[$place] = Standings::rank($rows);
        }

        return $out;
    }

    /**
     * Po przeliczeniu ostatniej kolejki grupowej: tworzy od nowa drabinkę fazy pucharowej (kolejki 6-9),
     * wpisuje zespoły do 1/8 finału i oznacza miejsca 5-6 jako odpadnięte po kolejce 5.
     * Wywoływane przez ScoreMatchday; ponowne przeliczenie kolejki 5 kasuje wyniki fazy pucharowej
     * (trzeba wtedy przeliczyć też kolejne kolejki).
     */
    public static function drawKnockout(Competition $competition): void
    {
        $pending = Fixture::where('competition_id', $competition->id)
            ->where('round', '<=', self::GROUP_ROUNDS)
            ->whereNull('home_goals')
            ->exists();

        if ($pending || CompetitionEntry::where('competition_id', $competition->id)->count() !== self::TEAMS) {
            return; // faza grupowa niekompletna
        }

        $seats = self::knockoutSeats($competition);

        Fixture::where('competition_id', $competition->id)->where('round', '>', self::GROUP_ROUNDS)->delete();

        $now = now();
        $rows = [];
        foreach (self::knockoutFixtures() as $match) {
            $first = $match['round'] === self::GROUP_ROUNDS + 1;
            $rows[] = [
                'competition_id' => $competition->id,
                'round' => $match['round'],
                'home_seat' => $match['home'],
                'away_seat' => $match['away'],
                'home_entry_id' => $first ? ($seats[$match['home']] ?? null) : null,
                'away_entry_id' => $first ? ($seats[$match['away']] ?? null) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Fixture::insert($rows);

        CompetitionEntry::where('competition_id', $competition->id)
            ->whereNotIn('id', array_values($seats))
            ->update(['eliminated_round' => self::GROUP_ROUNDS]);
    }

    /**
     * Kolejność końcowa (do tabel końcowych): zwycięzca, finalista, potem odpadnięci w półfinale, ćwierćfinale,
     * 1/8 finału (w każdej grupie według miejsca w drabince), a na końcu miejsca 5 i 6 z grup według wirtualnych tabel.
     *
     * @return Collection<int, array{team: mixed, stats: array<string, mixed>}>
     */
    public static function finalOrder(Competition $competition): Collection
    {
        $entries = CompetitionEntry::with('seasonTeam')->where('competition_id', $competition->id)->get()->keyBy('id');
        $knockout = Fixture::where('competition_id', $competition->id)->where('round', '>', self::GROUP_ROUNDS)->get();

        if ($knockout->isEmpty()) {
            // Faza grupowa trwa albo nie ruszyła: kolejność z wirtualnych tabel miejsc.
            return collect(self::placeTables($competition))->flatten(1)
                ->map(fn ($row) => ['team' => $row['team'], 'stats' => collect($row)->except(['team', 'entry_id'])->all()])
                ->values();
        }

        // Najdalsza runda i miejsce drabinki z 1/8 finału (lepsze miejsce = wyżej w swojej grupie odpadnięć).
        $reached = [];
        $seat = [];
        foreach ($knockout as $fixture) {
            foreach ([[$fixture->home_entry_id, $fixture->home_seat], [$fixture->away_entry_id, $fixture->away_seat]] as [$id, $s]) {
                if ($id === null) {
                    continue;
                }
                $reached[$id] = max($reached[$id] ?? 0, $fixture->round);
                if ($fixture->round === self::GROUP_ROUNDS + 1) {
                    $seat[$id] = (int) $s;
                }
            }
            if ($fixture->round === self::ROUNDS && $fixture->winner_entry_id) {
                $reached[$fixture->winner_entry_id] = self::ROUNDS + 1;
            }
        }

        $ordered = collect(array_keys($reached))
            ->sort(fn (int $a, int $b) => [$reached[$b], $seat[$a] ?? 99] <=> [$reached[$a], $seat[$b] ?? 99])
            ->values();

        $rows = $ordered->map(fn ($id) => ['team' => $entries[$id]->seasonTeam, 'stats' => ['round' => $reached[$id], 'seat' => $seat[$id] ?? null]]);

        // Miejsca 5 i 6 z grup.
        $places = self::placeTables($competition);
        foreach ([5, 6] as $place) {
            foreach ($places[$place] ?? [] as $row) {
                $rows->push(['team' => $row['team'], 'stats' => collect($row)->except(['team', 'entry_id'])->all()]);
            }
        }

        return $rows->values();
    }
}
