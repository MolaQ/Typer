<?php

namespace App\Support;

use App\Enums\MatchdayStatus;
use App\Enums\QuestionSide;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Fixture;
use App\Models\Matchday;
use App\Models\MatchdayQuestion;
use App\Models\SeasonTeam;
use App\Models\TeamScore;
use App\Models\Tip;
use App\Models\TipAnswer;
use App\Models\User;

/**
 * Podgląd rywali gracza w kolejce (mecze z terminarza z rundą = numer kolejki), z widocznością zależną od etapu:
 *  - typowanie otwarte: każdy widzi, czy rywal typował; premium widzi też rozstrzygnięcie i ryzyko (gwiazdki),
 *  - typowanie zamknięte, bez wyników: każdy widzi, czy typował, i ryzyko; premium widzi dokładny typ,
 *  - po wpisaniu wyników: pełne szczegóły dla wszystkich (typ, bonusy, wynik meczu).
 * Ryzyko = liczba odpowiedzi rywala na 5 pytań ofensywnych i 5 defensywnych zestawu tych rozgrywek.
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
     *   outcome: ?string, tip: ?string, offense: ?int, defense: ?int, bonus: ?string, score: ?string}>
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

        // Liczba odpowiedzi rywali: user_id => zestaw => strona => liczba.
        $rivalUsers = $fixtures->map(fn ($f) => ($mine->contains($f->home_entry_id) ? $f->away : $f->home)?->seasonTeam?->user_id)->filter()->unique();
        $slots = MatchdayQuestion::where('matchday_id', $matchday->id)->get(['id', 'competition_type', 'side'])->keyBy('id');
        $counts = [];
        foreach (TipAnswer::where('matchday_id', $matchday->id)->whereIn('user_id', $rivalUsers)->get(['user_id', 'matchday_question_id']) as $answer) {
            $slot = $slots->get($answer->matchday_question_id);
            if ($slot) {
                $counts[$answer->user_id][$slot->competition_type->value][$slot->side->value] = ($counts[$answer->user_id][$slot->competition_type->value][$slot->side->value] ?? 0) + 1;
            }
        }

        $tips = Tip::where('matchday_id', $matchday->id)->whereIn('user_id', $rivalUsers)->get()->keyBy('user_id');
        $scores = $phase === self::PLAYED
            ? TeamScore::where('matchday_id', $matchday->id)->get()->groupBy('season_team_id')
            : collect();

        $out = [];
        foreach ($fixtures as $fixture) {
            $competition = $competitions[$fixture->competition_id];
            $rivalEntry = $mine->contains($fixture->home_entry_id) ? $fixture->away : $fixture->home;
            $rival = $rivalEntry?->seasonTeam;
            $set = $competition->type->questionSet()->value;

            $row = [
                'competition' => $competition->name ?: $competition->type->label(),
                'type' => $competition->type,
                'trophy' => $competition->trophyKey(),
                'fixture_id' => $fixture->id,
                'rival' => $rival?->name ?? Fixture::VIRTUAL_OPPONENT,
                'bot' => (bool) $rival?->is_bot,
                'virtual' => $rival === null,
                'tipped' => null, 'outcome' => null, 'tip' => null, 'offense' => null, 'defense' => null,
                'bonus' => null, 'score' => $fixture->isPlayed() ? $fixture->score() : null,
                // Bilans bezpośredni to dodatek premium.
                'h2h' => $rival && $premium ? self::headToHead($viewer, $rival) : null,
            ];

            if ($rival && ! $rival->is_bot) {
                $tip = $tips->get($rival->user_id);
                $offense = $counts[$rival->user_id][$set][QuestionSide::Offensive->value] ?? 0;
                $defense = $counts[$rival->user_id][$set][QuestionSide::Defensive->value] ?? 0;

                $row['tipped'] = $tip !== null;

                if ($tip && ($phase === self::PLAYED || ($phase === self::CLOSED && $premium))) {
                    $row['tip'] = $tip->score();
                } elseif ($tip && $phase === self::OPEN && $premium) {
                    $row['outcome'] = self::outcome($tip->lech_goals, $tip->opponent_goals);
                }

                if ($phase !== self::OPEN || $premium) {
                    $row['offense'] = $offense;
                    $row['defense'] = $defense;
                }
            }

            // Po wynikach: typ i bonusy z rozliczenia (także boty).
            if ($rival && $phase === self::PLAYED) {
                $score = $scores->get($rival->id)?->first(fn ($s) => $s->question_set->value === $set);
                if ($score) {
                    $row['tipped'] = $score->has_tip;
                    $row['tip'] = $score->has_tip ? $score->tip_lech.':'.$score->tip_opponent : null;
                    $row['bonus'] = __('Offense :offense, defense :defense', ['offense' => $score->offense_bonus, 'defense' => $score->defense_bonus]);
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
        $set = $fixture->competition->type->questionSet();
        $teams = [$fixture->home?->seasonTeam, $fixture->away?->seasonTeam];
        $users = collect($teams)->filter()->pluck('user_id')->filter();

        $tips = $matchday ? Tip::where('matchday_id', $matchday->id)->whereIn('user_id', $users)->get()->keyBy('user_id') : collect();
        $counts = [];
        if ($matchday && $users->isNotEmpty()) {
            $slots = MatchdayQuestion::where('matchday_id', $matchday->id)->where('competition_type', $set->value)->get(['id', 'side'])->keyBy('id');
            foreach (TipAnswer::where('matchday_id', $matchday->id)->whereIn('user_id', $users)->whereIn('matchday_question_id', $slots->keys())->get(['user_id', 'matchday_question_id']) as $answer) {
                $side = $slots[$answer->matchday_question_id]->side->value;
                $counts[$answer->user_id][$side] = ($counts[$answer->user_id][$side] ?? 0) + 1;
            }
        }

        $scores = $matchday && $phase === self::PLAYED
            ? TeamScore::where('matchday_id', $matchday->id)->where('question_set', $set->value)
                ->whereIn('season_team_id', collect($teams)->filter()->pluck('id'))->get()->keyBy('season_team_id')
            : collect();

        $sides = [];
        foreach ($teams as $index => $team) {
            $goals = $index === 0 ? $fixture->home_goals : $fixture->away_goals;
            $side = [
                'name' => $team?->name ?? ($index === 1 && $fixture->isBye() ? Fixture::VIRTUAL_OPPONENT : __('Seat :number', ['number' => $index === 0 ? $fixture->home_seat : $fixture->away_seat])),
                'owner' => $team?->user?->name,
                // Profil zespołu (tylko zespoły graczy; boty nie mają profilu).
                'profile' => $team?->user ? route('team.show', $team->user) : null,
                'user_id' => $team?->user_id,
                'season_team_id' => $team?->id,
                'bot' => (bool) $team?->is_bot,
                'virtual' => $index === 1 && $fixture->isBye(),
                'goals' => $goals,
                'winner' => $fixture->winner_entry_id !== null && (int) $fixture->winner_entry_id === (int) ($index === 0 ? $fixture->home_entry_id : $fixture->away_entry_id),
                'tipped' => null, 'outcome' => null, 'tip' => null, 'tipped_at' => null, 'offense' => null, 'defense' => null, 'score' => null,
            ];

            if ($team && ! $team->is_bot) {
                $tip = $tips->get($team->user_id);
                $side['tipped'] = $tip !== null;

                if ($tip && ($phase === self::PLAYED || ($phase === self::CLOSED && $premium))) {
                    $side['tip'] = $tip->score();
                } elseif ($tip && $phase === self::OPEN && $premium) {
                    $side['outcome'] = self::outcome($tip->lech_goals, $tip->opponent_goals);
                }

                if ($phase !== self::OPEN || $premium) {
                    $side['offense'] = $counts[$team->user_id][QuestionSide::Offensive->value] ?? 0;
                    $side['defense'] = $counts[$team->user_id][QuestionSide::Defensive->value] ?? 0;
                }
            }

            // Po wynikach pełne rozliczenie (także boty).
            if ($team && ($score = $scores->get($team->id))) {
                $side['tipped'] = $score->has_tip;
                $side['tip'] = $score->has_tip ? $score->tip_lech.':'.$score->tip_opponent : null;
                $side['tipped_at'] = $score->tipped_at;
                $side['score'] = $score;
            }

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
     * Bilans bezpośredni z rywalem (człowiek albo bot) ze wszystkich sezonów i rozgrywek
     * oraz historia spotkań od ostatniego do pierwszego.
     *
     * @return array{won: int, drawn: int, lost: int, meetings: array<int, array{season: string, competition: string, round: int, score: string, outcome: string}>}
     */
    public static function headToHead(User $viewer, SeasonTeam $rival): array
    {
        $rivalTeams = $rival->user_id
            ? SeasonTeam::where('user_id', $rival->user_id)->pluck('id')
            : SeasonTeam::where('bot_id', $rival->bot_id)->pluck('id');

        $mine = CompetitionEntry::whereIn('season_team_id', SeasonTeam::where('user_id', $viewer->id)->select('id'))->pluck('id');
        $theirs = CompetitionEntry::whereIn('season_team_id', $rivalTeams)->pluck('id');

        $fixtures = Fixture::with('competition.season:id,number')
            ->whereNotNull('home_goals')
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->whereIn('home_entry_id', $mine)->whereIn('away_entry_id', $theirs))
                ->orWhere(fn ($w) => $w->whereIn('home_entry_id', $theirs)->whereIn('away_entry_id', $mine)))
            ->get()
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
    private static function outcome(int $lech, int $opponent): string
    {
        return match (true) {
            $lech > $opponent => __('Lech wins'),
            $lech < $opponent => __('Lech loses'),
            default => __('A draw'),
        };
    }
}
