<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Enums\KnockoutStage;
use App\Enums\League;
use App\Enums\SeasonStatus;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\FinalStanding;
use App\Models\Fixture;
use App\Models\HallOfFameAward;
use App\Models\Season;
use App\Models\SeasonTeam;
use Illuminate\Support\Collection;

/**
 * Historia zespołu (gracza albo bota) sezon po sezonie, pogrupowana według typów rozgrywek:
 *  - ligi i podwórkowa: miejsce i zmiana ligi w następnym sezonie (awans / spadek / utrzymanie, z listy kolejnego sezonu, previous_id),
 *  - Puchar Polski i puchary europejskie: etap, do którego doszedł zespół (ostatni rozegrany mecz), zwycięzca,
 *  - Liga Legend: miejsce w tabeli końcowej.
 * Punkty Hall of Fame za rozgrywki z hall_of_fame_awards. Tylko zakończone sezony.
 */
final class TeamHistory
{
    public const GROUPS = ['league', 'cup', 'europe', 'legends'];

    /**
     * @param  Collection<int, HallOfFameAward>  $awards
     * @return array<int, array{season: Season, points: float, trophies: array<int, string>, rows: array<int, array<string, mixed>>}>
     */
    public static function for(?int $userId, ?int $botId, Collection $awards): array
    {
        $owner = $userId !== null ? 'user_id' : 'bot_id';
        $ownerId = $userId ?? $botId;

        $seasons = Season::where('status', SeasonStatus::Finished->value)->orderByDesc('number')->get();
        $teams = SeasonTeam::whereIn('season_id', $seasons->pluck('id'))->where($owner, $ownerId)->get()->keyBy('season_id');
        $standings = FinalStanding::where($owner, $ownerId)->get()->keyBy('competition_id');

        // Miejsca na listach następnych sezonów (zmiana ligi po awansach i spadkach).
        $next = SeasonTeam::whereIn('previous_id', $teams->pluck('id'))->get()->keyBy('previous_id');

        $entries = CompetitionEntry::whereIn('season_team_id', $teams->pluck('id'))->get();
        $competitions = Competition::whereIn('id', $entries->pluck('competition_id'))->get()->keyBy('id');
        $fixtures = Fixture::where(fn ($q) => $q->whereIn('home_entry_id', $entries->pluck('id'))->orWhereIn('away_entry_id', $entries->pluck('id')))
            ->whereNotNull('winner_entry_id')
            ->get();

        $out = [];
        foreach ($seasons as $season) {
            $team = $teams->get($season->id);
            $seasonAwards = $awards->where('season_id', $season->id);

            if (! $team && $seasonAwards->isEmpty()) {
                continue;
            }

            $rows = [];
            $teamEntries = $team ? $entries->where('season_team_id', $team->id) : collect();
            $nextTeam = $team ? $next->get($team->id) : null;
            foreach ($teamEntries->sortBy(fn (CompetitionEntry $e) => CompetitionColors::rank($competitions->get($e->competition_id)?->trophyKey() ?? '')) as $entry) {
                $competition = $competitions->get($entry->competition_id);
                if (! $competition) {
                    continue;
                }
                $played = $fixtures->filter(fn ($f) => $f->home_entry_id === $entry->id || $f->away_entry_id === $entry->id)->sortBy('round');
                $place = $standings->get($competition->id)?->place;
                $row = self::row($competition, $entry, $place, $played, $nextTeam);

                if ($row === null) {
                    continue;
                }

                $row += ['winner' => false, 'move' => null, 'move_tone' => null];
                $row['points'] = round((float) $seasonAwards->where('competition_id', $competition->id)->sum('points'), 1);
                $rows[] = $row;
            }

            $out[] = [
                'season' => $season,
                'points' => round((float) $seasonAwards->sum('points'), 1),
                'trophies' => $seasonAwards->pluck('trophy')->filter()->unique()->values()->all(),
                'rows' => $rows,
            ];
        }

        return $out;
    }

    /**
     * Jeden wiersz historii; null, gdy zespół nie zagrał w rozgrywkach ani jednego meczu (np. wolny los bez gry).
     *
     * @param  Collection<int, Fixture>  $played
     * @return array<string, mixed>|null wiersz: group, key, name, result, winner, move, move_tone
     */
    private static function row(Competition $competition, CompetitionEntry $entry, ?int $place, Collection $played, ?SeasonTeam $next): ?array
    {
        $type = $competition->type;
        $base = [
            'key' => $competition->trophyKey(),
            'name' => $competition->name ?: $type->label(),
        ];

        // Ligi i podwórkowa: miejsce i liga w następnym sezonie.
        if (in_array($type, [CompetitionType::League, CompetitionType::Swiss], true)) {
            $tier = $type === CompetitionType::League ? (int) $competition->tier : League::Podworkowa->value;
            [$move, $tone] = self::move($tier, $next);

            return $base + [
                'group' => 'league',
                'result' => $place ? __(':place. place', ['place' => $place]) : '—',
                'winner' => $place === 1,
                'move' => $move,
                'move_tone' => $tone,
            ];
        }

        if ($type === CompetitionType::Legends) {
            return $base + ['group' => 'legends', 'result' => $place ? __(':place. place', ['place' => $place]) : '—', 'winner' => $place === 1];
        }

        $last = $played->last();
        if (! $last) {
            return null;
        }

        $won = $last->winner_entry_id === $entry->id;
        $final = $last->round >= ($type === CompetitionType::Cup ? CupBracket::ROUNDS : EuropeanCup::ROUNDS);

        if ($type === CompetitionType::Cup) {
            return $base + [
                'group' => 'cup',
                'result' => $won && $final ? __('Winner') : KnockoutStage::labelFor($last->round),
                'winner' => $won && $final,
            ];
        }

        // Puchary europejskie: faza grupowa (kolejki 1-5) albo etap fazy pucharowej.
        return $base + [
            'group' => 'europe',
            'result' => match (true) {
                $won && $final => __('Winner'),
                EuropeanCup::isGroupRound($last->round) => __('Group stage'),
                default => EuropeanCup::stageLabel($last->round),
            },
            'winner' => $won && $final,
        ];
    }

    /**
     * Zmiana ligi: awans / spadek do ligi z listy następnego sezonu, utrzymanie albo brak (zespół nie gra dalej).
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function move(int $tier, ?SeasonTeam $next): array
    {
        if (! $next) {
            return [null, null];
        }

        $to = League::forPosition($next->position);

        return match (true) {
            $to->value < $tier => [__('Promoted to :league', ['league' => $to->label()]), 'up'],
            $to->value > $tier => [__('Relegated to :league', ['league' => $to->label()]), 'down'],
            default => [__('Stayed in :league', ['league' => $to->label()]), 'stay'],
        };
    }
}
