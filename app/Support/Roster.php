<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Enums\League;
use App\Enums\MatchdayStatus;
use App\Enums\SeasonStatus;
use App\Models\Bot;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\DesignatedPlayer;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\Tip;
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
    /** Próby transakcji zmieniającej listę (Laravel powtarza ją po zakleszczeniu). */
    public const ATTEMPTS = 3;

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
        PageCache::flush();
        self::lockSeason($seasonId);

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

            // Wypchnięty bot przechodzi na koniec listy (Liga podwórkowa) tylko wtedy, gdy lista ma mniej niż 512 zespołów.
            // Przy pełnej liście (od 15.10.2026) z gry wypada najsłabszy bot: przed startem sezonu boty za tym miejscem
            // przesuwają się o jedno (każde miejsce bierze bota z miejsca wyżej, ostatni pauzuje), w trakcie sezonu
            // pauzuje wypchnięty bot (wyniki miejsc zostają przy botach, które je zdobyły).
            if ($botId) {
                if (SeasonTeam::where('season_id', $seasonId)->count() < SeasonTeam::CUP_SIZE) {
                    self::appendBot($seasonId, $botId);
                } elseif (! Competition::where('season_id', $seasonId)->exists()) {
                    self::shiftBots($seasonId, $bot->position, $botId);
                }

                Bot::syncActive($seasonId);
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

    /**
     * Zmiany listy sezonu jedna po drugiej (15.10.2026): blokada wiersza sezonu przed blokadami miejsc. Bez niej dwa
     * równoczesne zapisy (np. dwa kliknięcia, zmiana roli w trakcie dopisywania) blokowały zakresy season_teams
     * w różnej kolejności i MySQL zgłaszał zakleszczenie (1213). Sezon blokują też SetUpSeason, ApproveSeason i FinishSeason,
     * więc kolejność blokad jest wszędzie ta sama: najpierw sezon, potem lista. Działa w transakcji wołającego.
     */
    private static function lockSeason(int $seasonId): void
    {
        Season::query()->whereKey($seasonId)->lockForUpdate()->first(['id']);
    }

    /**
     * Przed startem sezonu: boty za miejscem $position przesuwają się o jedno miejsce w dół listy (miejsce dostaje bota
     * z miejsca wyżej, pierwsze za $position wypchniętego $botId), a ostatni bot listy wypada i pauzuje.
     */
    private static function shiftBots(int $seasonId, int $position, int $botId): void
    {
        $rows = SeasonTeam::where('season_id', $seasonId)
            ->whereNotNull('bot_id')
            ->where('position', '>', $position)
            ->orderBy('position')
            ->get(['id', 'bot_id']);

        $carry = $botId;
        foreach ($rows as $row) {
            [$carry, $current] = [$row->bot_id, $carry];
            SeasonTeam::whereKey($row->id)->update(['bot_id' => $current]);
        }
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

            // Nowy zespół gra od najbliższej kolejki bez wyników (pary losujemy od nowa).
            LegendsLeague::redrawPending($legends);
        }
    }

    /**
     * Zespół przestaje grać w Lidze Legend. Bez rozegranych meczów wpis znika, a z meczami zostaje w tabeli
     * jako odpadnięty (żeby wyniki rywali się nie zmieniły). Pary najbliższej kolejki losujemy od nowa.
     */
    private static function leaveLegends(SeasonTeam $team): void
    {
        $legends = Competition::where('season_id', $team->season_id)->where('type', CompetitionType::Legends->value)->first();
        $entry = $legends ? CompetitionEntry::where('competition_id', $legends->id)->where('season_team_id', $team->id)->first() : null;

        if (! $legends || ! $entry) {
            return;
        }

        $played = (int) Fixture::where('competition_id', $legends->id)
            ->whereNotNull('home_goals')
            ->where(fn ($q) => $q->where('home_entry_id', $entry->id)->orWhere('away_entry_id', $entry->id))
            ->max('round');

        if ($played === 0) {
            $entry->delete();
        } else {
            $entry->update(['eliminated_round' => $played]);
        }

        LegendsLeague::redrawPending($legends);
    }

    /**
     * Zwalnia miejsce gracza: wchodzi tam wolny bot z puli (pozycja i liga zostają).
     * Bez wolnego bota miejsce za pucharem (pozycja > 512) po prostu znika.
     */
    public static function release(SeasonTeam $team): bool
    {
        self::lockSeason($team->season_id);

        $bot = Bot::query()
            ->whereNotIn('id', SeasonTeam::where('season_id', $team->season_id)->whereNotNull('bot_id')->select('bot_id'))
            ->bestFirst()
            ->first();

        // Bot nie gra w Lidze Legend.
        self::leaveLegends($team);
        PageCache::flush();

        // Nierozliczone typy gracza (kolejki bez wyniku) znikają: ban, nieaktywność albo odejście z gry.
        if ($team->user_id) {
            self::dropOpenTips($team->season_id, $team->user_id);
        }

        if ($bot) {
            $team->update(['user_id' => null, 'bot_id' => $bot->id]);
            // Bot z puli (także pauzujący) wraca do gry.
            $bot->update(['is_active' => true]);

            return true;
        }

        if ($team->position > SeasonTeam::CUP_SIZE) {
            $team->delete();

            return true;
        }

        return false;
    }

    /** Kasuje typy gracza w nierozegranych kolejkach sezonu (rozliczone zostają w historii). */
    public static function dropOpenTips(int $seasonId, int $userId): int
    {
        $open = Matchday::where('season_id', $seasonId)->where('status', '!=', MatchdayStatus::Played->value)->pluck('id');

        if ($open->isEmpty()) {
            return 0;
        }

        // Wystawieni zawodnicy też (desygnowanie, Support\Squad).
        DesignatedPlayer::whereIn('matchday_id', $open)->where('user_id', $userId)->delete();

        return Tip::whereIn('matchday_id', $open)->where('user_id', $userId)->delete();
    }

    /**
     * Gracz usuwa konto: jego zespoły w nieukończonych sezonach przejmują boty, a nierozliczone typy znikają
     * (rozliczone wyniki zespołu zostają w tabelach).
     */
    public static function releaseUser(User $user): void
    {
        $teams = SeasonTeam::where('user_id', $user->id)
            ->whereHas('season', fn ($q) => $q->where('status', '!=', SeasonStatus::Finished->value))
            ->get();

        foreach ($teams as $team) {
            DB::transaction(fn () => self::release($team), self::ATTEMPTS);
        }
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
            }, self::ATTEMPTS);
        }

        return ['added' => $added, 'released' => $released];
    }
}
