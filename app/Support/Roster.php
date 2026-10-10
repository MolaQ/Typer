<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Enums\League;
use App\Enums\SeasonStatus;
use App\Models\Bot;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Obsada list przedsezonowych: wstawianie graczy do lig i zwalnianie ich miejsc.
 * Wspólna logika dla strony "Lista zespołów" i dla automatu po zmianie ról użytkownika.
 *
 * Zasada: gracz zajmuje miejsce najwyżej sklasyfikowanego bota w lidze (bot przechodzi na koniec listy,
 * czyli do Ligi podwórkowej),
 * a zwalniane miejsce gracza zajmuje wolny bot, więc ligi i terminarz zawsze mają komplet zespołów.
 */
class Roster
{
    /**
     * Wstawia gracza do ligi. Liga 1-10 bez botów: false (nic się nie zmienia).
     * Liga podwórkowa bez botów: gracz na koniec listy.
     */
    public static function place(int $seasonId, int $userId, League $league): bool
    {
        $placed = self::placeTeam($seasonId, $userId, $league);

        // Informacje systemowe: gracz trafił do ligi.
        if ($placed && ($user = User::find($userId))) {
            SystemFeed::record('leagues', ':team joined :league', ['team' => $user->team_name ?: $user->name, 'league' => $league->label()], 'team.show', ['user' => $user->id], $user->id);
        }

        return $placed;
    }

    private static function placeTeam(int $seasonId, int $userId, League $league): bool
    {
        $bot = SeasonTeam::where('season_id', $seasonId)
            ->whereNull('user_id')
            ->inLeague($league)
            ->orderBy('position')
            ->lockForUpdate()
            ->first();

        if ($bot) {
            $botId = $bot->bot_id;
            $bot->update(['user_id' => $userId, 'bot_id' => null]);
            self::joinLegends($seasonId, $bot->id);

            // Wypchnięty bot nie znika: przechodzi na koniec listy, czyli do Ligi podwórkowej.
            if ($botId) {
                self::appendBot($seasonId, $botId);
            }

            return true;
        }

        if ($league === League::Podworkowa) {
            $end = max(League::TOP_TEAMS, (int) SeasonTeam::where('season_id', $seasonId)->max('position')) + 1;
            $now = now();

            $teamId = SeasonTeam::insertGetId([
                'season_id' => $seasonId,
                'position' => $end,
                'user_id' => $userId,
                'bot_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            self::joinSwiss($seasonId, $teamId, $end);
            self::joinLegends($seasonId, $teamId);

            return true;
        }

        return false;
    }

    /** Dopisuje bota na koniec listy (Liga podwórkowa), także do jej rozgrywek, jeśli sezon już je ma. */
    private static function appendBot(int $seasonId, int $botId): void
    {
        $end = max(League::TOP_TEAMS, (int) SeasonTeam::where('season_id', $seasonId)->max('position')) + 1;
        $now = now();
        $teamId = SeasonTeam::insertGetId([
            'season_id' => $seasonId,
            'position' => $end,
            'user_id' => null,
            'bot_id' => $botId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::joinSwiss($seasonId, $teamId, $end);
    }

    /**
     * Dopisuje nowy zespół (na końcu listy) do Ligi podwórkowej, jeśli sezon ma już jej rozgrywki.
     * Gracz, który zajął miejsce bota, ma wpis od początku, więc tu trafiają tylko dopisani na koniec.
     */
    private static function joinSwiss(int $seasonId, int $seasonTeamId, int $position): void
    {
        $swiss = Competition::where('season_id', $seasonId)->where('type', CompetitionType::Swiss->value)->first();

        if ($swiss) {
            CompetitionEntry::firstOrCreate(
                ['competition_id' => $swiss->id, 'season_team_id' => $seasonTeamId],
                ['seed' => $position - League::TOP_TEAMS],
            );
        }
    }

    /** Dopisuje zespół gracza do Ligi Legend sezonu (jeśli już istnieje), na koniec rozstawienia. */
    private static function joinLegends(int $seasonId, int $seasonTeamId): void
    {
        $legends = Competition::where('season_id', $seasonId)->where('type', CompetitionType::Legends->value)->first();

        if ($legends && ! CompetitionEntry::where('competition_id', $legends->id)->where('season_team_id', $seasonTeamId)->exists()) {
            CompetitionEntry::create([
                'competition_id' => $legends->id,
                'season_team_id' => $seasonTeamId,
                'seed' => (int) CompetitionEntry::where('competition_id', $legends->id)->max('seed') + 1,
            ]);
        }
    }

    private static function leaveLegends(SeasonTeam $team): void
    {
        CompetitionEntry::where('season_team_id', $team->id)
            ->whereIn('competition_id', Competition::where('season_id', $team->season_id)->where('type', CompetitionType::Legends->value)->select('id'))
            ->delete();
    }

    /**
     * Zwalnia miejsce gracza: wchodzi tam wolny bot z puli (pozycja i liga zostają).
     * Bez wolnego bota miejsce za pucharem (pozycja > 512) po prostu znika.
     */
    public static function release(SeasonTeam $team): bool
    {
        $bot = Bot::query()
            ->whereNotIn('id', SeasonTeam::where('season_id', $team->season_id)->whereNotNull('bot_id')->select('bot_id'))
            ->orderBy('sort_order')
            ->first();

        // Bot nie gra w Lidze Legend.
        self::leaveLegends($team);

        if ($bot) {
            $team->update(['user_id' => null, 'bot_id' => $bot->id]);

            return true;
        }

        if ($team->position > SeasonTeam::CUP_SIZE) {
            $team->delete();

            return true;
        }

        return false;
    }

    /** Zwalnia miejsca wszystkich graczy z listy, którzy nie mogą już grać (ban, brak roli). */
    public static function releaseIneligible(int $seasonId): int
    {
        $ids = SeasonTeam::where('season_id', $seasonId)
            ->whereNotNull('user_id')
            ->whereNotIn('user_id', Players::eligible()->select('id'))
            ->pluck('id');

        $released = 0;

        foreach (SeasonTeam::whereIn('id', $ids)->orderBy('position')->get() as $team) {
            if (self::release($team)) {
                $released++;
            }
        }

        return $released;
    }

    /**
     * Wywoływane po każdej zmianie ról użytkownika (strona Użytkownicy).
     * W każdym nieukończonym sezonie, który ma już listę:
     *  - gracz z rolą (bez bana) bez miejsca trafia do Ligi podwórkowej (admin może go przenieść),
     *  - użytkownik zbanowany albo bez roli traci miejsce na rzecz bota.
     *
     * @return array{added: int, released: int}
     */
    public static function syncUser(User $user): array
    {
        $user->unsetRelation('roles');
        $canPlay = Players::canPlay($user);
        $added = 0;
        $released = 0;

        $seasons = Season::query()
            ->whereIn('status', [SeasonStatus::Draft->value, SeasonStatus::Approved->value, SeasonStatus::Active->value])
            ->whereHas('teams')
            ->get();

        foreach ($seasons as $season) {
            DB::transaction(function () use ($season, $user, $canPlay, &$added, &$released): void {
                $team = SeasonTeam::where('season_id', $season->id)->where('user_id', $user->id)->first();

                if ($canPlay && ! $team) {
                    if (self::place($season->id, $user->id, League::Podworkowa)) {
                        $added++;

                        Audit::log(
                            'season_list.player_assigned',
                            $user,
                            [],
                            ['league' => League::Podworkowa->label()],
                            ($user->team_name ?: $user->name).' ('.$season->title.')',
                        );
                    }
                } elseif (! $canPlay && $team) {
                    $name = $team->name;

                    if (self::release($team)) {
                        $released++;

                        Audit::log('season_list.player_released', $user, [], [], $name.' ('.$season->title.')');
                    }
                }
            });
        }

        return ['added' => $added, 'released' => $released];
    }
}
