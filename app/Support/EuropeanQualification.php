<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Enums\SeasonStatus;
use App\Models\Competition;
use App\Models\FinalStanding;
use App\Models\HallOfFameAward;
use App\Models\Season;
use App\Models\SeasonTeam;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Przydział zespołów do Ligi Mistrzów, Ligi Europy i Ligi Konferencji (po 24, każdy zespół najwyżej w jednych).
 *
 *  - Sezon 1 (brak zakończonego sezonu wcześniej): kolejno z listy sezonu.
 *  - Kolejne sezony: jedna lista kwalifikacji z poprzedniego sezonu. Najpierw tytuły w kolejności ustawionej w panelu
 *    (`european_qualification_order`, domyślnie DEFAULT_ORDER), potem reszta według poziomu ligi i miejsca
 *    (Ekstraklasa 2-10, I liga …, C klasa, Liga podwórkowa). Zespół z kilkoma tytułami liczy się raz, przy najwyższym,
 *    a jego miejsce przechodzi na kolejny zespół z listy.
 *  - Zawsze najpierw zespoły ludzi, boty tylko uzupełniają wolne miejsca. Gracz, którego nie ma w nowym sezonie,
 *    traci miejsce (bierze je następny z listy). Nowe zespoły bez historii są na końcu (według listy sezonu).
 *  - Pierwsze 24 zespoły grają w LM, kolejne 24 w LE, następne 24 w LK. Rozstawienie = miejsce na liście.
 */
final class EuropeanQualification
{
    public const PER_CUP = 24;

    /** Domyślna kolejność kryteriów (od najbardziej zasłużonych). */
    public const DEFAULT_ORDER = [
        'champions_winner',
        'legends_winner',
        'league_champion',
        'cup_winner',
        'europa_winner',
        'golden_ball',
        'mvp',
        'golden_boot',
        'golden_gloves',
        'conference_winner',
        'lower_champions',
        'cup_finalist',
        'legends_second',
        'champions_finalist',
        'europa_finalist',
        'conference_finalist',
    ];

    /**
     * Kryteria z opisem do panelu.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'champions_winner' => __('Champions League winner'),
            'legends_winner' => __('Legends League winner'),
            'league_champion' => __('Ekstraklasa champion'),
            'cup_winner' => __('Polish Cup winner'),
            'europa_winner' => __('Europa League winner'),
            'golden_ball' => __('Golden Ball'),
            'mvp' => __('MVP'),
            'golden_boot' => __('Golden Boot'),
            'golden_gloves' => __('Golden Gloves'),
            'conference_winner' => __('Conference League winner'),
            'lower_champions' => __('Champions of I liga … C klasa (from the highest league)'),
            'cup_finalist' => __('Polish Cup finalist'),
            'legends_second' => __('Legends League 2nd place'),
            'champions_finalist' => __('Champions League finalist'),
            'europa_finalist' => __('Europa League finalist'),
            'conference_finalist' => __('Conference League finalist'),
        ];
    }

    /**
     * Kolejność kryteriów z panelu. Nieznane klucze pomijamy, brakujące dopisujemy na końcu w kolejności domyślnej.
     *
     * @return list<string>
     */
    public static function order(): array
    {
        $saved = DB::table('european_qualification_order')->orderBy('position')->pluck('key')->all();
        $order = array_values(array_intersect($saved, self::DEFAULT_ORDER));

        return array_merge($order, array_values(array_diff(self::DEFAULT_ORDER, $order)));
    }

    /** @param  list<string>  $order */
    public static function saveOrder(array $order): void
    {
        $order = array_values(array_intersect($order, self::DEFAULT_ORDER));
        $now = now();

        DB::transaction(function () use ($order, $now): void {
            DB::table('european_qualification_order')->delete();
            DB::table('european_qualification_order')->insert(array_map(
                fn (string $key, int $index) => ['key' => $key, 'position' => $index + 1, 'created_at' => $now, 'updated_at' => $now],
                $order,
                array_keys($order),
            ));
        });
    }

    /** Przywrócenie kolejności domyślnej. */
    public static function resetOrder(): void
    {
        DB::table('european_qualification_order')->delete();
    }

    /**
     * Składy pucharów: typ => [rozstawienie => id zespołu z listy sezonu].
     *
     * @return array<string, array<int, int>>
     */
    public static function allocate(Season $season): array
    {
        $ranked = self::ranking($season);
        $out = [];

        foreach ([CompetitionType::Champions, CompetitionType::Europa, CompetitionType::Conference] as $index => $type) {
            $slice = array_slice($ranked, $index * self::PER_CUP, self::PER_CUP);
            $out[$type->value] = $slice === [] ? [] : array_combine(range(1, count($slice)), array_column($slice, 'team'));
        }

        return $out;
    }

    /**
     * Pełna lista kwalifikacji nowego sezonu (ludzie, potem boty), z powodem miejsca (klucz kryterium,
     * 'fill' = miejsce w tabeli, 'list' = lista sezonu).
     *
     * @return list<array{team: int, reason: string}>
     */
    public static function ranking(Season $season): array
    {
        $teams = SeasonTeam::where('season_id', $season->id)->orderBy('position')->get(['id', 'user_id', 'bot_id', 'previous_id', 'position']);
        $previous = Season::where('status', SeasonStatus::Finished->value)->where('number', '<', $season->number)->orderByDesc('number')->first();

        $list = [];
        foreach ($previous ? self::history($previous) : [] as [$reason, $row]) {
            $team = self::match($teams, $row);

            if ($team !== null && ! isset($list[$team->id])) {
                $list[$team->id] = ['team' => $team->id, 'reason' => $reason, 'human' => $team->user_id !== null];
            }
        }

        // Zespoły bez historii (np. nowi gracze, sezon 1) według listy sezonu.
        foreach ($teams as $team) {
            $list[$team->id] ??= ['team' => $team->id, 'reason' => 'list', 'human' => $team->user_id !== null];
        }

        $humans = array_filter($list, fn (array $row) => $row['human']);
        $bots = array_filter($list, fn (array $row) => ! $row['human']);

        return array_map(fn (array $row) => ['team' => $row['team'], 'reason' => $row['reason']], [...$humans, ...$bots]);
    }

    /**
     * Zespoły poprzedniego sezonu w kolejności kwalifikacji (z powtórzeniami, odsiewa je ranking()).
     *
     * @return list<array{0: string, 1: FinalStanding|HallOfFameAward}> [kryterium, wiersz z season_team_id, user_id, bot_id]
     */
    private static function history(Season $previous): array
    {
        $competitions = Competition::where('season_id', $previous->id)->get(['id', 'type', 'tier']);
        $standings = FinalStanding::where('season_id', $previous->id)->get(['competition_id', 'season_team_id', 'user_id', 'bot_id', 'place']);

        // Miejsce $place w rozgrywkach typu $type (i poziomu $tier).
        $at = function (CompetitionType $type, int $place, ?int $tier = null) use ($competitions, $standings): array {
            $ids = $competitions->filter(fn (Competition $c) => $c->type === $type && ($tier === null || $c->tier === $tier))->pluck('id');

            return $standings->whereIn('competition_id', $ids)->where('place', $place)->values()->all();
        };

        $award = fn (string $kind): array => HallOfFameAward::where('season_id', $previous->id)->where('kind', $kind)
            ->whereNotNull('season_team_id')->get(['season_team_id', 'user_id', 'bot_id'])->all();

        $rows = [];
        foreach (self::order() as $key) {
            $found = match ($key) {
                'champions_winner' => $at(CompetitionType::Champions, 1),
                'legends_winner' => $at(CompetitionType::Legends, 1),
                'league_champion' => $at(CompetitionType::League, 1, 1),
                'cup_winner' => $at(CompetitionType::Cup, 1),
                'europa_winner' => $at(CompetitionType::Europa, 1),
                'golden_ball', 'mvp', 'golden_boot', 'golden_gloves' => $award($key),
                'conference_winner' => $at(CompetitionType::Conference, 1),
                'lower_champions' => array_merge(...array_map(fn (int $tier) => $at(CompetitionType::League, 1, $tier), range(2, 10))),
                'cup_finalist' => $at(CompetitionType::Cup, 2),
                'legends_second' => $at(CompetitionType::Legends, 2),
                'champions_finalist' => $at(CompetitionType::Champions, 2),
                'europa_finalist' => $at(CompetitionType::Europa, 2),
                'conference_finalist' => $at(CompetitionType::Conference, 2),
                default => [],
            };

            foreach ($found as $row) {
                $rows[] = [$key, $row];
            }
        }

        // Reszta: ligi od Ekstraklasy według miejsca, potem Liga podwórkowa.
        $tiers = [];
        foreach ($competitions as $competition) {
            if ($competition->type === CompetitionType::League || $competition->type === CompetitionType::Swiss) {
                $tiers[$competition->id] = $competition->type === CompetitionType::Swiss ? 11 : (int) $competition->tier;
            }
        }

        $fill = $standings->filter(fn (FinalStanding $s) => isset($tiers[$s->competition_id]))
            ->sortBy(fn (FinalStanding $s) => [$tiers[$s->competition_id], $s->place]);

        foreach ($fill as $row) {
            $rows[] = ['fill', $row];
        }

        return $rows;
    }

    /**
     * Zespół nowego sezonu dla wiersza z poprzedniego: przez previous_id, a gdy go brak, po graczu albo bocie.
     *
     * @param  Collection<int, SeasonTeam>  $teams
     */
    private static function match(Collection $teams, FinalStanding|HallOfFameAward $row): ?SeasonTeam
    {
        return $teams->firstWhere('previous_id', $row->season_team_id)
            ?? ($row->user_id ? $teams->firstWhere('user_id', $row->user_id) : ($row->bot_id ? $teams->firstWhere('bot_id', $row->bot_id) : null));
    }
}
