<?php

namespace App\Support;

/**
 * Treści „Zasady gry” i „FAQ” (skrót regulaminu w wersji 1.0) do akordeonów na stronie głównej
 * i na stronach /rules oraz /faq. Każda pozycja to [tytuł, treść].
 */
class Guide
{
    /** Najważniejsze punkty regulaminu. */
    public static function rules(): array
    {
        return [
            [__('Season and matchdays'), __('A season has 9 matchdays, each with one real Lech Poznań match. You give one tip (Lech : opponent after 90 minutes) per matchday and it counts in every competition you play in. Tipping closes at kick-off.')],
            [__('Points for the tip'), __('1 point for the outcome, 1 for the goal difference and 1 for the exact score, up to 3 points. No tip means 0 points.')],
            [__('Bonus questions'), __('In every matchday each competition type has 5 offensive and 5 defensive yes/no questions. Each correct answer is worth 1 point, no answer gives nothing, and one wrong answer clears the whole set of that side.')],
            [__('Team score'), __('Your score in a match is the points for the tip plus your offensive bonus minus the defensive bonus of your rival, never below 0.')],
            [__('Competitions'), __('10 leagues of 10 teams (Ekstraklasa to C klasa), Liga podwórkowa (Swiss system), Puchar Polski (512 teams, a fixed bracket of 9 rounds), Liga Mistrzów, Europy and Konferencji, Liga Legend and Złota Liga for supporters.')],
            [__('League table'), __('A win gives 3 points, a draw 1 and a loss 0. Ties are decided by goal balance, goals, wins, draws, exact tips, hit differences, hit outcomes, bonuses, Hall of Fame points and the place on the list.')],
            [__('Puchar Polski'), __('The favourite plays on the left and the winner takes the better seed of the pair. A draw goes to the team that saved its tip earlier (at an equal time the higher seed), shown as “after penalties”.')],
            [__('Promotion and relegation'), __('By default 4 teams go up and 4 go down, and leagues are cleaned of bots with up to 6 extra swaps. The top 3 of leagues 1–10 play in Liga Mistrzów, Europy and Konferencji next season.')],
            [__('Liga Legend'), __('All teams run by people play. After each matchday the weakest drop out (256, 128 and so on down to a final of 2 teams). The criteria add up from round 1.')],
            [__('Individual awards'), __('Season MVP: the most correct outcomes (then exact scores, then goal differences). Golden Ball: the most goals in your league. Golden Gloves: the most points from defensive bonuses. Only players compete, the league question set counts, and on a tie the higher league place wins. All three give Hall of Fame points.')],
            [__('Inactivity and bots'), __('5 matchdays in a row without a tip turn your team into a bot from the next season. Bots tip a random score from 0–3 : 0–3 and do not answer questions.')],
        ];
    }

    /** Najczęstsze pytania graczy. */
    public static function faq(): array
    {
        return [
            [__('Is tipping free?'), __('Yes. Tipping is always free, premium only adds extras.')],
            [__('Can I change my tip?'), __('Yes, as many times as you like until kick-off. The time of the last save decides drawn cup ties.')],
            [__('What if I forget to tip?'), __('You get 0 points for that matchday. Premium players can set a default tip that is used automatically.')],
            [__('When are the points counted?'), __('After the match the admin enters the result and the correct answers and settles the matchday. The tables update at that moment.')],
            [__('What does premium give?'), __('A preview of your rivals’ tips, a default tip, your exact place among the players, team name changes without approval, and every payment counts towards Złota Liga. Plans start at 5 zł a week.')],
            [__('How do I get into Złota Liga?'), __('The 10 players with the highest payments over the last 12 months play in it. On a tie the earlier last payment wins.')],
            [__('What is a Koziołek?'), __('An exact score tip, worth the full 3 points for the tip.')],
            [__('Where can I see my history?'), __('In LechTYPER under the History tab, and on your team page in the Hall of Fame.')],
        ];
    }
}
