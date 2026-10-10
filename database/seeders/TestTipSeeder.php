<?php

namespace Database\Seeders;

use App\Enums\MatchdayStatus;
use App\Enums\QuestionSide;
use App\Enums\RoleName;
use App\Enums\SeasonStatus;
use App\Models\Matchday;
use App\Models\MatchdayQuestion;
use App\Models\SeasonTeam;
use App\Models\Tip;
use App\Models\TipAnswer;
use App\Models\User;
use App\Support\PlayerCompetitions;
use App\Support\Players;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Typy testowe (tylko ręcznie: php artisan db:seed --class=TestTipSeeder).
 * Dla każdej nierozliczonej kolejki aktywnego sezonu (otwartej albo już po gwizdku) bierze 25 losowych graczy z listy sezonu
 * (bez adminów) i zapisuje im losowy typ 0-3 : 0-3 oraz 0-2 odpowiedzi ofensywne i 0-2 defensywne
 * w każdym zestawie pytań ich rozgrywek. Czas zapisu jest losowy, zawsze przed pierwszym gwizdkiem
 * i nie później niż teraz. Wcześniejszy typ i odpowiedzi wylosowanego gracza w tej kolejce są nadpisywane.
 */
class TestTipSeeder extends Seeder
{
    private const PLAYERS = 25;

    private const MAX_ANSWERS_PER_SIDE = 2;

    public function run(): void
    {
        $matchdays = Matchday::whereHas('season', fn ($q) => $q->where('status', SeasonStatus::Active->value))
            ->orderBy('number')
            ->get()
            // Otwarte do typowania i zamknięte, ale jeszcze nierozliczone (po gwizdku, przed wpisaniem wyniku),
            // żeby po ResultMatchSeederze było co liczyć. Rozliczonych nie ruszamy.
            ->filter(fn (Matchday $m) => $m->kickoff_at !== null && $m->status === MatchdayStatus::Planned && $m->isFilled());

        if ($matchdays->isEmpty()) {
            $this->command?->warn('Brak nierozliczonych kolejek z rywalem i godziną meczu.');

            return;
        }

        foreach ($matchdays as $matchday) {
            $users = User::query()
                ->whereIn('id', SeasonTeam::where('season_id', $matchday->season_id)->whereNotNull('user_id')->select('user_id'))
                ->whereHas('roles')
                ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', [...Players::BLOCKING_ROLES, RoleName::Admin->value]))
                ->inRandomOrder()
                ->limit(self::PLAYERS)
                ->get();

            $slots = MatchdayQuestion::where('matchday_id', $matchday->id)->get()->groupBy(fn ($s) => $s->competition_type->value);

            DB::transaction(function () use ($matchday, $users, $slots) {
                foreach ($users as $user) {
                    $savedAt = self::randomTime($matchday->kickoff_at);

                    Tip::updateOrCreate(
                        ['matchday_id' => $matchday->id, 'user_id' => $user->id],
                        ['lech_goals' => random_int(0, 3), 'opponent_goals' => random_int(0, 3), 'is_default' => false, 'saved_at' => $savedAt],
                    );

                    TipAnswer::where('matchday_id', $matchday->id)->where('user_id', $user->id)->delete();

                    // Odpowiedzi tylko w zestawach rozgrywek, w których gracz gra (jak przy zwykłym typowaniu).
                    foreach (PlayerCompetitions::types($user->id, $matchday->season_id, $matchday->number) as $type) {
                        $set = $slots->get($type->value, collect());

                        foreach (QuestionSide::cases() as $side) {
                            $picked = $set->where('side', $side)->shuffle()->take(random_int(0, self::MAX_ANSWERS_PER_SIDE));

                            foreach ($picked as $slot) {
                                TipAnswer::create([
                                    'matchday_id' => $matchday->id,
                                    'user_id' => $user->id,
                                    'matchday_question_id' => $slot->id,
                                    'answer' => (bool) random_int(0, 1),
                                ]);
                            }
                        }
                    }
                }
            });

            $this->command?->info("Kolejka {$matchday->number}: typy dla {$users->count()} graczy".($slots->isEmpty() ? ' (bez pytań: kolejka nie ma jeszcze zestawów).' : '.'));
        }
    }

    /**
     * Losowy moment z ostatnich 5 dni przed pierwszym gwizdkiem (albo przed teraz), z mikrosekundami.
     * Daty w aplikacji są niemutowalne (CarbonImmutable), dlatego CarbonInterface.
     */
    private static function randomTime(CarbonInterface $kickoff): CarbonInterface
    {
        $end = now()->lt($kickoff) ? now() : $kickoff->copy()->subMinute();
        $start = $end->copy()->subDays(5);

        return Carbon::createFromTimestamp(random_int($start->getTimestamp(), $end->getTimestamp() - 1), config('app.timezone'))
            ->setMicrosecond(random_int(0, 999999));
    }
}
