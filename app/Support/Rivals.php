<?php

namespace App\Support;

use App\Enums\CompetitionType;
use App\Enums\MatchdayStatus;
use App\Enums\PlayerPosition;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\DesignatedPlayer;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Models\SeasonTeam;
use App\Models\TeamScore;
use App\Models\Tip;
use App\Models\User;

/**
 * Podgląd rywali gracza w kolejce (mecze z terminarza z rundą = numer kolejki), z widocznością zależną od etapu:
 *  - typowanie otwarte: każdy widzi, czy rywal typował; premium widzi rozstrzygnięcie typu (Lech / remis / rywal),
 *  - typowanie zamknięte, bez wyników: każdy widzi rozstrzygnięcie typu; premium widzi dokładny typ z czasem zapisu,
 *  - po wpisaniu wyników: pełne szczegóły dla wszystkich (typ, bonusy, wynik).
 * Boty typują dopiero przy przeliczeniu kolejki, więc przed wynikami nie mają typu.
 */
final class Rivals
{
    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const PLAYED = 'played';

    public static function phase(Matchday $matchday): string
    {
        if ($matchday->status === MatchdayStatus::Played) {
            return self::PLAYED;
        }

        return $matchday->isOpenForTips() ? self::OPEN : self::CLOSED;
    }

    /**
     * @return array<int, array{competition: string, rival: string, bot: bool, virtual: bool, tipped: ?bool,
     *   outcome: ?string, tip: ?string, bonus: ?string, score: ?string}>
     */
    public static function forUser(User $viewer, Matchday $matchday): array
    {
        $team = SeasonTeam::where('season_id', $matchday->season_id)->where('user_id', $viewer->id)->first();

        if (! $team) {
            return [];
        }

        $phase = self::phase($matchday);
        $premium = Premium::isActive($viewer);
        $round = $matchday->number;

        $competitions = Competition::where('season_id', $matchday->season_id)->get()->keyBy('id');
        $mine = CompetitionEntry::whereIn('competition_id', $competitions->keys())
            ->where('season_team_id', $team->id)
            ->where(fn ($q) => $q->whereNull('eliminated_round')->orWhere('eliminated_round', '>=', $round))
            ->pluck('id');

        $fixtures = Fixture::with(['home.seasonTeam.user:id,name,team_name', 'home.seasonTeam.bot:id,name', 'away.seasonTeam.user:id,name,team_name', 'away.seasonTeam.bot:id,name'])
            ->where('round', $round)
            ->whereIn('competition_id', $competitions->keys())
            ->where(fn ($q) => $q->whereIn('home_entry_id', $mine)->orWhereIn('away_entry_id', $mine))
            ->get();

        if ($fixtures->isEmpty()) {
            return [];
        }

        $rivalUsers = $fixtures->map(fn ($f) => ($mine->contains($f->home_entry_id) ? $f->away : $f->home)?->seasonTeam?->user_id)->filter()->unique();
        $tips = Tip::where('matchday_id', $matchday->id)->whereIn('user_id', $rivalUsers)->get()->keyBy('user_id');
        $scores = $phase === self::PLAYED
            ? TeamScore::where('matchday_id', $matchday->id)->get()->groupBy('season_team_id')
            : collect();

        $out = [];
        foreach ($fixtures as $fixture) {
            $competition = $competitions[$fixture->competition_id];
            $rivalEntry = $mine->contains($fixture->home_entry_id) ? $fixture->away : $fixture->home;
            $rival = $rivalEntry?->seasonTeam;
            $set = $competition->type->scoreSet()->value;

            $row = [
                'competition' => $competition->name ?: $competition->type->label(),
                'type' => $competition->type,
                'trophy' => $competition->trophyKey(),
                'fixture_id' => $fixture->id,
                'rival' => $rival?->name ?? Fixture::VIRTUAL_OPPONENT,
                'bot' => (bool) $rival?->is_bot,
                'virtual' => $rival === null,
                'tipped' => null, 'outcome' => null, 'tip' => null,
                'bonus' => null, 'score' => $fixture->isPlayed() ? $fixture->score() : null,
                // Bilans bezpośredni to dodatek premium.
                'h2h' => $rival && $premium ? self::headToHead($viewer, $rival) : null,
            ];

            if ($rival && ! $rival->is_bot) {
                $tip = $tips->get($rival->user_id);

                $row['tipped'] = $tip !== null;

                if ($tip && ($phase === self::PLAYED || ($phase === self::CLOSED && $premium))) {
                    $row['tip'] = $tip->score();
                } elseif ($tip && ($phase === self::CLOSED || $premium)) {
                    $row['outcome'] = self::outcome($tip, $matchday);
                }
            }

            // Po wynikach: typ i bonusy z rozliczenia (także boty).
            if ($rival && $phase === self::PLAYED) {
                $score = $scores->get($rival->id)?->first(fn ($s) => $s->score_set->value === $set);
                if ($score) {
                    $row['tipped'] = $score->has_tip;
                    $row['tip'] = $score->has_tip ? $score->tip_lech.':'.$score->tip_opponent : null;
                    $row['bonus'] = (string) __('Offense :offense, defense :defense', ['offense' => $score->offense_bonus, 'defense' => $score->defense_bonus]);
                }
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * Szczegóły jednego meczu terminarza (okno na stronie wyników) z tą samą widocznością co podgląd rywali:
     * przed zamknięciem typowania i przed wynikami widać tylko to, co pozwala etap i premium oglądającego,
     * a po wynikach pełne rozliczenie obu stron (typ, punkty za typ, bonusy, wynik ofensywny i bramki).
     *
     * @return array{phase: string, premium: bool, played: bool, score: ?string, sides: array<int, array<string, mixed>>}
     */
    public static function fixture(Fixture $fixture, ?Matchday $matchday, ?User $viewer): array
    {
        $fixture->loadMissing(['competition', 'home.seasonTeam.user:id,name,team_name', 'home.seasonTeam.bot:id,name', 'away.seasonTeam.user:id,name,team_name', 'away.seasonTeam.bot:id,name']);

        $phase = $matchday ? self::phase($matchday) : self::OPEN;
        $premium = $viewer !== null && Premium::isActive($viewer);
        $set = $fixture->competition->type->scoreSet();
        $teams = [$fixture->home?->seasonTeam, $fixture->away?->seasonTeam];
        $users = collect($teams)->filter()->pluck('user_id')->filter();

        $tips = $matchday ? Tip::where('matchday_id', $matchday->id)->whereIn('user_id', $users)->get()->keyBy('user_id') : collect();

        $scores = $matchday && $phase === self::PLAYED
            ? TeamScore::where('matchday_id', $matchday->id)->where('score_set', $set->value)
                ->whereIn('season_team_id', collect($teams)->filter()->pluck('id'))->get()->keyBy('season_team_id')
            : collect();

        $sides = [];
        foreach ($teams as $index => $team) {
            $goals = $index === 0 ? $fixture->home_goals : $fixture->away_goals;
            $side = [
                'name' => $team?->name ?? ($index === 1 && $fixture->isBye() ? Fixture::VIRTUAL_OPPONENT : __('Seat :number', ['number' => $index === 0 ? $fixture->home_seat : $fixture->away_seat])),
                'owner' => $team?->user?->name,
                // Profil zespołu gracza albo bota.
                'profile' => $team?->profileUrl(),
                'user_id' => $team?->user_id,
                'season_team_id' => $team?->id,
                // Aktualne tytuły (miniatury trofeów w nagłówku okna, App\Support\Champions).
                'trophies' => Champions::of($team),
                'bot' => (bool) $team?->is_bot,
                'virtual' => $index === 1 && $fixture->isBye(),
                'goals' => $goals,
                'winner' => $fixture->winner_entry_id !== null && (int) $fixture->winner_entry_id === (int) ($index === 0 ? $fixture->home_entry_id : $fixture->away_entry_id),
                'tipped' => null, 'outcome' => null, 'tip' => null, 'tipped_at' => null, 'tip_order' => null, 'score' => null,
            ];

            if ($team && ! $team->is_bot) {
                $tip = $tips->get($team->user_id);
                $side['tipped'] = $tip !== null;

                if ($tip && ($phase === self::PLAYED || ($phase === self::CLOSED && $premium))) {
                    $side['tip'] = $tip->score();
                    $side['tipped_at'] = $tip->saved_at;
                    $side['tip_order'] = $tip->save_order;
                } elseif ($tip && $matchday && ($phase === self::CLOSED || $premium)) {
                    $side['outcome'] = self::outcome($tip, $matchday);
                }
            }

            // Po wynikach pełne rozliczenie (także boty).
            if ($team && ($score = $scores->get($team->id))) {
                $side['tipped'] = $score->has_tip;
                $side['tip'] = $score->has_tip ? $score->tip_lech.':'.$score->tip_opponent : null;
                $side['tipped_at'] = $score->tipped_at;
                $side['tip_order'] = $score->tip_order;
                $side['score'] = $score;
            }

            $side['lineup'] = $team && $matchday ? self::lineup($team, $side['score'], $matchday, $set, $phase, $premium) : null;

            $sides[] = $side;
        }

        return [
            'phase' => $phase,
            'premium' => $premium,
            'played' => $fixture->isPlayed(),
            'score' => $fixture->isPlayed() ? $fixture->score() : null,
            'sides' => $sides,
        ];
    }

    /**
     * Wystawieni zawodnicy strony meczu (desygnowanie, Support\Squad) z widocznością jak typ:
     * przed gwizdkiem wszyscy widzą liczbę, premium pozycje; po gwizdku wszyscy widzą pozycje (B O P N), premium nazwiska;
     * po rozliczeniu nazwiska widzą wszyscy.
     * Bot wystawia skład przy przeliczeniu kolejki, więc przed wynikami nic nie pokazujemy.
     *
     * @return array{count: int, positions: array<string, int>|null, names: array<int, array{label: string, tone: string|null}>|null}|null
     */
    private static function lineup(SeasonTeam $team, ?TeamScore $score, Matchday $matchday, CompetitionType $set, string $phase, bool $premium): ?array
    {
        $userId = $score ? $score->user_id : $team->user_id;
        $botId = $score ? $score->bot_id : $team->bot_id;

        if ($userId === null && $phase !== self::PLAYED) {
            return null;
        }

        $picks = DesignatedPlayer::with('player')->where('matchday_id', $matchday->id)->where('score_set', $set->value)
            ->where($userId !== null ? 'user_id' : 'bot_id', $userId ?? $botId)
            ->get()->filter(fn (DesignatedPlayer $d) => $d->player !== null)
            ->sortBy(fn (DesignatedPlayer $d) => array_search($d->player?->position->value, PlayerPosition::values(), true));
        $players = $picks->map(fn (DesignatedPlayer $d) => $d->player)->filter();

        $positions = [];
        foreach (PlayerPosition::cases() as $position) {
            $positions[$position->short()] = $players->where('position', $position)->count();
        }

        $showPositions = $phase !== self::OPEN || $premium;
        // Po rozliczeniu kolejki skład to już historia meczu: nazwiska widzą wszyscy.
        $showNames = $phase === self::PLAYED || ($phase !== self::OPEN && $premium);

        return [
            'count' => $players->count(),
            'positions' => $showPositions ? $positions : null,
            // Po rozliczeniu: 'attack' = punkty ataku dla nas (gol, asysta), 'defense' = punkty obrony (czyste konto).
            'names' => $showNames ? $picks->map(fn (DesignatedPlayer $d) => [
                'label' => $d->player?->position->short().' '.$d->player?->name,
                'tone' => $phase !== self::PLAYED ? null : ((int) $d->attack > 0 ? 'attack' : ((int) $d->defense > 0 ? 'defense' : null)),
            ])->values()->all() : null,
        ];
    }

    /**
     * Bilans bezpośredni z rywalem (człowiek albo bot) ze wszystkich sezonów i rozgrywek
     * oraz historia spotkań od ostatniego do pierwszego.
     *
     * @return array{won: int, drawn: int, lost: int, meetings: array<int, array{season: string, competition: string, round: int, score: string, outcome: string}>}
     */
    public static function headToHead(User $viewer, SeasonTeam $rival): array
    {
        // Liczą się tylko kolejki, w których oba miejsca zajmowali ci właściciele (gracz za bota nie przejmuje meczów bota, Ownership).
        $rivalKey = Ownership::key($rival->user_id, $rival->bot_id);
        $mineTeams = CompetitionEntry::whereIn('season_team_id', PlayerStats::ownerTeamIds($viewer->id, null))->pluck('season_team_id', 'id');
        $theirTeams = CompetitionEntry::whereIn('season_team_id', PlayerStats::ownerTeamIds($rival->user_id, $rival->bot_id))->pluck('season_team_id', 'id');
        $mine = $mineTeams->keys();
        $theirs = $theirTeams->keys();

        $fixtures = Fixture::with('competition.season:id,number')
            ->whereNotNull('home_goals')
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->whereIn('home_entry_id', $mine)->whereIn('away_entry_id', $theirs))
                ->orWhere(fn ($w) => $w->whereIn('home_entry_id', $theirs)->whereIn('away_entry_id', $mine)))
            ->get()
            ->filter(function (Fixture $f) use ($mine, $mineTeams, $theirTeams, $viewer, $rivalKey) {
                $home = $mine->contains($f->home_entry_id);
                $seasonId = (int) $f->competition?->getAttribute('season_id');
                $myTeam = (int) $mineTeams[$home ? $f->home_entry_id : $f->away_entry_id];
                $theirTeam = (int) $theirTeams[$home ? $f->away_entry_id : $f->home_entry_id];

                return Ownership::playedBy($seasonId, $myTeam, (int) $f->round, $viewer->id)
                    && Ownership::at($seasonId, $theirTeam, (int) $f->round) === $rivalKey;
            })
            ->sortByDesc(fn ($f) => [$f->competition->season->number, $f->round, $f->id]);

        $out = ['won' => 0, 'drawn' => 0, 'lost' => 0, 'meetings' => []];
        foreach ($fixtures as $fixture) {
            $home = $mine->contains($fixture->home_entry_id);
            $entryId = $home ? $fixture->home_entry_id : $fixture->away_entry_id;
            $for = (int) ($home ? $fixture->home_goals : $fixture->away_goals);
            $against = (int) ($home ? $fixture->away_goals : $fixture->home_goals);

            // Remis rozstrzygnięty czasem typu (puchar) liczymy jako wygraną albo porażkę.
            $outcome = $for === $against && $fixture->winner_entry_id
                ? ((int) $fixture->winner_entry_id === $entryId ? 'W' : 'L')
                : ($for > $against ? 'W' : ($for === $against ? 'D' : 'L'));

            $out[['W' => 'won', 'D' => 'drawn', 'L' => 'lost'][$outcome]]++;
            $out['meetings'][] = [
                'season' => $fixture->competition->season->roman_number,
                'competition' => $fixture->competition->name ?: $fixture->competition->type->label(),
                'round' => (int) $fixture->round,
                'score' => $for.':'.$against.($fixture->decided_by_time ? ' '.__('(pen.)') : ''),
                'outcome' => $outcome,
            ];
        }

        return $out;
    }

    /** Rozstrzygnięcie z perspektywy Lecha. */
    /** Rozstrzygnięcie typu do kafelka: „Lech”, „Remis” albo nazwa rywala Lecha. */
    private static function outcome(Tip $tip, Matchday $matchday): string
    {
        return match (true) {
            $tip->lech_goals > $tip->opponent_goals => 'Lech',
            $tip->lech_goals < $tip->opponent_goals => $matchday->opponent ?: __('Rival'),
            default => __('Draw'),
        };
    }
}
