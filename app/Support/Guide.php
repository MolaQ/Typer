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
            [__('Team score'), __('Your score in a match is the points for the tip plus your offensive bonus minus the defensive bonus of your rival, never below 0.')],
            [__('Competitions'), __('10 leagues of 10 teams (Ekstraklasa to C klasa), Liga podwórkowa (Swiss system), Puchar Polski (512 teams, a fixed bracket of 9 rounds), Liga Mistrzów, Europy and Konferencji and Liga Legend.')],
            [__('League table'), __('A win gives 3 points, a draw 1 and a loss 0. Ties are decided by goal balance, goals, wins, draws, exact tips, hit differences, hit outcomes, bonuses, Hall of Fame points and the place on the list.')],
            [__('Puchar Polski'), __('The favourite plays on the left and the winner takes the better seed of the pair. A draw goes to the team whose tip was saved first (every save gets its own number; bots and missing tips come last, then the higher seed), shown as “after penalties”.')],
            [__('European cups'), __('Liga Mistrzów, Europy and Konferencji have 24 teams each; a team plays in only one of them. Matchdays 1-5: four groups of six, everyone plays everyone once, league points and tie-breakers. The top four of each group go through, the fifth and sixth places are out. Teams from the same group place are ranked against each other: seeds 1-4 are the group winners, 5-8 the runners-up, 9-12 the third places and 13-16 the fourth places. Matchdays 6-9: round of 16 (1-16, 2-15 …), quarter-final, semi-final and final, played like Puchar Polski, so the best group winner can meet the second best only in the final.')],
            [__('Promotion and relegation'), __('After the season bots go to the bottom of every table, then 4 teams go up and 4 go down, and finally every bot swaps places with the best human below it, so humans fill the leagues from the top. Bots beyond place 512 of the list rest for a season. The top 3 of leagues 1–10 play in Liga Mistrzów, Europy and Konferencji next season.')],
            [__('Liga Legend'), __('All teams run by people play. Every matchday each team plays two duels in a cycle: 1-2, 2-3 … and the first with the last (matchday 1 from the pre-season list, then by the current table, the higher team on the left). The table is a league table (3 points for a win, 1 for a draw, criteria as in the leagues) and counts from matchday 1. Two losses in one matchday knock the team out: its results stay in the table, but it no longer plays. A draw is not a loss. When two teams are left, they play one match. After matchday 9 the top of the whole table wins.')],
            [__('Individual awards'), __('Season MVP: correct outcomes plus your players\' attack and defence points in league matches. Golden Ball: the most goals in the whole season from all competitions (points for the tip plus your players\' attack points, without the rival\'s own goals). Golden Boot: the most attack points of your players (goals and assists) from all competitions. Golden Gloves: the most defence points of your players (clean sheets) in league matches. On a tie the team with more trophies this season wins, then the higher league place. Top scorer of a league: the most goals in its table, on a tie the higher place. All awards give Hall of Fame points.')],
            [__('Inactivity and bots'), __('5 matchdays in a row without a tip turn your team into a bot from the next season. Bots tip a random score from 0–3 : 0–3.')],
        ];
    }

    /** Najczęstsze pytania graczy. */
    public static function faq(): array
    {
        return [
            [__('Is tipping free?'), __('Yes. Tipping is always free, premium only adds extras.')],
            [__('Can I change my tip?'), __('Yes, as many times as you like until kick-off. The time of the last save decides drawn cup ties.')],
            [__('What if I forget to tip?'), __('You get 0 points for that matchday. The first time you open a matchday, the tip 0:0 is saved for you, so change it before kick-off.')],
            [__('When are the points counted?'), __('After the match the admin enters the result and settles the matchday. The tables update at that moment.')],
            [__('What does premium give?'), __('A preview of your rivals’ tips, statistics from the other players’ tips while you tip, your exact place among the players, team name changes without approval and more statistics. Plans start at 5 zł a week.')],
            [__('What is a Koziołek?'), __('An exact score tip, worth the full 3 points for the tip.')],
            [__('Where can I see my history?'), __('In LechTYPER under the History tab, and on your team page in the Hall of Fame.')],
        ];
    }
}
