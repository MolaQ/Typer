<?php

namespace Database\Seeders;

use App\Enums\QuestionSide;
use App\Models\Question;
use Illuminate\Database\Seeder;

/**
 * Przykładowy bank pytań tak/nie: 45 ofensywnych (o ataku Lecha) i 45 defensywnych (o obronie i grze rywala).
 * Pełny zestaw 8 typów rozgrywek w kolejce wymaga co najmniej 40 pytań z każdej strony, bo w jednej
 * kolejce pytania się nie powtarzają. Można uruchamiać wielokrotnie (istniejące pytania są pomijane),
 * a własne pytania dodajesz na stronie "Bank pytań".
 *
 *   php artisan db:seed --class=QuestionsSeeder
 */
class QuestionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::offensive() as $text) {
            Question::firstOrCreate(['text' => $text], ['side' => QuestionSide::Offensive->value, 'is_active' => true]);
        }

        foreach (self::defensive() as $text) {
            Question::firstOrCreate(['text' => $text], ['side' => QuestionSide::Defensive->value, 'is_active' => true]);
        }
    }

    /** @return array<int, string> */
    public static function offensive(): array
    {
        return [
            'Czy Lech strzeli co najmniej jednego gola?',
            'Czy Lech strzeli co najmniej dwa gole?',
            'Czy Lech strzeli co najmniej trzy gole?',
            'Czy Lech strzeli co najmniej cztery gole?',
            'Czy Lech strzeli gola w pierwszej połowie?',
            'Czy Lech strzeli gola w drugiej połowie?',
            'Czy Lech strzeli gola w obu połowach?',
            'Czy Lech strzeli gola w pierwszych 15 minutach?',
            'Czy Lech strzeli gola przed 30. minutą?',
            'Czy Lech strzeli gola po 75. minucie?',
            'Czy Lech strzeli gola w doliczonym czasie gry?',
            'Czy Lech strzeli pierwszego gola w meczu?',
            'Czy Lech będzie prowadził po pierwszej połowie?',
            'Czy Lech strzeli gola z rzutu karnego?',
            'Czy Lech będzie miał rzut karny?',
            'Czy Lech strzeli gola głową?',
            'Czy Lech strzeli gola bezpośrednio z rzutu wolnego?',
            'Czy Lech strzeli gola po rzucie rożnym?',
            'Czy gola dla Lecha strzeli zawodnik, który wszedł z ławki?',
            'Czy gola dla Lecha strzeli pomocnik?',
            'Czy gola dla Lecha strzeli obrońca?',
            'Czy gola dla Lecha strzeli napastnik?',
            'Czy rywal strzeli samobója?',
            'Czy dwóch różnych zawodników Lecha strzeli gola?',
            'Czy zawodnik Lecha strzeli dwa gole w meczu?',
            'Czy Lech odda co najmniej 5 strzałów celnych?',
            'Czy Lech odda co najmniej 8 strzałów celnych?',
            'Czy Lech wykona co najmniej 6 rzutów rożnych?',
            'Czy Lech wykona więcej rzutów rożnych niż rywal?',
            'Czy Lech będzie miał większe posiadanie piłki niż rywal?',
            'Czy Lech będzie miał co najmniej 60% posiadania piłki?',
            'Czy Lech odda więcej strzałów niż rywal?',
            'Czy Lech trafi w słupek lub poprzeczkę?',
            'Czy Lech strzeli gola w ciągu 10 minut po przerwie?',
            'Czy Lech wyrówna wynik po tym, jak rywal wyjdzie na prowadzenie?',
            'Czy Lech wygra pierwszą połowę?',
            'Czy Lech wygra drugą połowę?',
            'Czy Lech strzeli gola między 31. a 45. minutą?',
            'Czy Lech strzeli gola między 46. a 60. minutą?',
            'Czy Lech strzeli gola między 61. a 75. minutą?',
            'Czy Lech strzeli co najmniej dwa gole w drugiej połowie?',
            'Czy Lech strzeli co najmniej dwa gole w pierwszej połowie?',
            'Czy Lech odda co najmniej 15 strzałów (celnych i niecelnych)?',
            'Czy Lech strzeli gola w ciągu pierwszych 5 minut drugiej połowy?',
            'Czy Lech strzeli gola z rzutu karnego lub rzutu wolnego?',
        ];
    }

    /** @return array<int, string> */
    public static function defensive(): array
    {
        return [
            'Czy Lech zachowa czyste konto?',
            'Czy rywal strzeli co najmniej jednego gola?',
            'Czy rywal strzeli co najmniej dwa gole?',
            'Czy rywal strzeli co najmniej trzy gole?',
            'Czy rywal strzeli gola w pierwszej połowie?',
            'Czy rywal strzeli gola w drugiej połowie?',
            'Czy rywal strzeli gola w obu połowach?',
            'Czy rywal strzeli gola w pierwszych 15 minutach?',
            'Czy rywal strzeli gola po 75. minucie?',
            'Czy rywal strzeli gola w doliczonym czasie gry?',
            'Czy rywal strzeli pierwszego gola w meczu?',
            'Czy rywal będzie prowadził po pierwszej połowie?',
            'Czy rywal strzeli gola z rzutu karnego?',
            'Czy rywal będzie miał rzut karny?',
            'Czy rywal strzeli gola głową?',
            'Czy rywal strzeli gola bezpośrednio z rzutu wolnego?',
            'Czy rywal strzeli gola po rzucie rożnym?',
            'Czy gola dla rywala strzeli zawodnik, który wszedł z ławki?',
            'Czy Lech strzeli samobója?',
            'Czy rywal odda co najmniej 5 strzałów celnych?',
            'Czy rywal odda mniej niż 3 strzały celne?',
            'Czy rywal wykona co najmniej 6 rzutów rożnych?',
            'Czy rywal będzie miał większe posiadanie piłki niż Lech?',
            'Czy rywal odda więcej strzałów niż Lech?',
            'Czy rywal trafi w słupek lub poprzeczkę?',
            'Czy Lech straci gola w ciągu 10 minut po przerwie?',
            'Czy rywal wyrówna wynik po golu Lecha?',
            'Czy rywal wygra pierwszą połowę?',
            'Czy rywal wygra drugą połowę?',
            'Czy rywal strzeli gola między 31. a 45. minutą?',
            'Czy rywal strzeli gola między 46. a 60. minutą?',
            'Czy rywal strzeli gola między 61. a 75. minutą?',
            'Czy rywal strzeli gola przed 30. minutą?',
            'Czy dwóch różnych zawodników rywala strzeli gola?',
            'Czy zawodnik rywala strzeli dwa gole w meczu?',
            'Czy zawodnik Lecha otrzyma czerwoną kartkę?',
            'Czy zawodnik rywala otrzyma czerwoną kartkę?',
            'Czy Lech otrzyma co najmniej 3 żółte kartki?',
            'Czy Lech popełni więcej fauli niż rywal?',
            'Czy bramkarz Lecha obroni co najmniej 3 strzały?',
            'Czy bramkarz Lecha obroni rzut karny?',
            'Czy rywal nie odda żadnego celnego strzału w pierwszej połowie?',
            'Czy rywal strzeli gola w ciągu pierwszych 5 minut drugiej połowy?',
            'Czy rywal wykona co najmniej 10 strzałów (celnych i niecelnych)?',
            'Czy rywal strzeli gola z rzutu karnego lub rzutu wolnego?',
        ];
    }
}