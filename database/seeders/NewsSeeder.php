<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\News;
use App\Models\NewsVote;
use App\Models\User;
use App\Support\Players;
use Illuminate\Database\Seeder;

/**
 * 25 przykładowych newsów do sprawdzenia wyglądu strony głównej (siatka 3 x 5 z paginacją, więc 2 strony).
 *
 * - Autor: pierwszy administrator (User::role() to zakres ze spatie/laravel-permission), a gdy go nie ma,
 *   pierwszy użytkownik w bazie.
 * - Daty publikacji: od najnowszego (2 godziny temu) co ok. 2,5 dnia wstecz, więc kolejność na stronie jest naturalna.
 *   Ostatni news to szkic (published_at = null): widać go tylko w panelu „Aktualności”.
 * - Oceny: losowa część graczy (Players::eligible()) daje kciuk w górę albo w dół, z przewagą pozytywnych.
 *   NewsVote::updateOrCreate() pilnuje jednej oceny gracza na news (unikalny klucz news_id + user_id).
 * - News::firstOrCreate() po tytule: ponowne uruchomienie nie dubluje wpisów.
 *
 *   php artisan db:seed --class=NewsSeeder
 */
class NewsSeeder extends Seeder
{
    public function run(): void
    {
        $author = User::role(RoleName::Admin->value)->orderBy('id')->first() ?? User::orderBy('id')->first();

        if (! $author) {
            $this->command?->warn('Brak użytkowników, newsy nie powstaną. Uruchom najpierw CreateAdminUserSeeder.');

            return;
        }

        $voters = Players::eligible()->pluck('id');
        $items = self::items();
        $last = count($items) - 1;

        foreach ($items as $index => [$title, $body]) {
            // Najnowszy news ma indeks 0. Odstęp 60 godzin daje ok. 2 miesiące wstecz dla 25 wpisów.
            $publishedAt = $index === $last ? null : now()->subHours(2 + $index * 60)->startOfMinute();

            $news = News::firstOrCreate(
                ['title' => $title],
                ['user_id' => $author->id, 'body' => $body, 'published_at' => $publishedAt],
            );

            if (! $news->wasRecentlyCreated || $publishedAt === null || $voters->isEmpty()) {
                continue;
            }

            // random() z kolekcji losuje podzbiór graczy; 70% ocen to kciuk w górę.
            foreach ($voters->random(random_int(0, min(12, $voters->count()))) as $userId) {
                NewsVote::updateOrCreate(
                    ['news_id' => $news->id, 'user_id' => $userId],
                    ['value' => random_int(1, 10) <= 7 ? 1 : -1],
                );
            }
        }
    }

    /**
     * Tytuł i treść (zwykły tekst, pusta linia oddziela akapity, jak w formularzu w panelu).
     * Od najnowszego do najstarszego.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function items(): array
    {
        return [
            ['Pozyskaliśmy nowego sponsora', "Z radością ogłaszamy, że nowym sponsorem LechTYPER-a została lokalna palarnia kawy z Wildy.\n\nSponsor ufundował nagrody dla zwycięzców Ekstraklasy i Pucharu Polski. Szczegóły pojawią się w zakładce Premium i wsparcie."],
            ['Typowanie kolejki 1 już otwarte', "Pierwsza kolejka nowego sezonu czeka na Wasze typy. Pamiętajcie, że typ można zmieniać do pierwszego gwizdka.\n\nNie zapomnijcie o pytaniach bonusowych: jedna zła odpowiedź zeruje cały zestaw danej strony."],
            ['Nowa strona: Informacje systemowe', "W lewym menu pojawiła się zakładka Informacje systemowe. Znajdziecie tam automatyczne wpisy o meczach, rozliczonych kolejkach, losowaniach i nowych graczach.\n\nKategorie włączacie i wyłączacie jednym kliknięciem, a dwuklik zostawia tylko wybraną."],
            ['Wyszukiwarka w nagłówku', "Od dziś w nagłówku strony działa wyszukiwarka. Szuka zespołów, graczy, rozgrywek, kolejek, punktów regulaminu i pytań z FAQ.\n\nWystarczą dwie litery, a wyniki pojawiają się na bieżąco."],
            ['Podsumowanie poprzedniego sezonu', "Za nami emocjonujący sezon. Mistrzem Ekstraklasy został zespół, który aż w sześciu kolejkach trafił dokładny wynik.\n\nGratulujemy wszystkim zdobywcom trofeów. Pełne podsumowanie znajdziecie w Hall of Fame."],
            ['Zmiany w Lidze Legend', "W Lidze Legend pytania bonusowe będą teraz trudniejsze. System wybiera pytania, na które najmniej graczy odpowiadało poprawnie.\n\nKryteria awansu pozostają bez zmian i liczą się narastająco od pierwszej rundy."],
            ['Przerwa reprezentacyjna', "W najbliższy weekend nie gramy, bo trwa przerwa na mecze reprezentacji. Kolejka 4 ruszy zgodnie z terminarzem Lecha.\n\nTo dobry moment, żeby przejrzeć statystyki swojego zespołu w zakładce Statystyki."],
            ['Rekord frekwencji w typowaniu', "W ostatniej kolejce typ oddało ponad 90% graczy z ludzkimi zespołami. To najlepszy wynik w historii LechTYPER-a.\n\nDziękujemy i liczymy, że w kolejnych kolejkach będzie jeszcze lepiej."],
            ['Jak działa Puchar Polski?', "Puchar to stała drabinka 512 zespołów i 9 rund. Faworyt gra po lewej, a zwycięzca przejmuje lepsze miejsce pary.\n\nPrzy remisie wygrywa zespół, który wcześniej zapisał typ. Na stronie pokazujemy wtedy dopisek „po karnych”."],
            ['Propozycje pytań bonusowych', "W stopce strony, w kolumnie Kontakt, możecie zaproponować własne pytanie bonusowe. Najlepsze trafią do banku pytań.\n\nPytanie musi mieć odpowiedź tak albo nie i dotyczyć meczu Lecha."],
            ['Mecz pucharowy w środę', "Uwaga, w tym tygodniu Lech gra w środę w Pucharze Polski. Typowanie zamyka się o godzinie rozpoczęcia meczu.\n\nSprawdźcie godzinę w zakładce LechTYPER, żeby nie przegapić terminu."],
            ['Premium: domyślny typ', "Gracze premium mogą ustawić domyślny typ. Jeśli zapomnicie wytypować kolejkę, system zapisze go za Was przy pierwszym gwizdku.\n\nTypowanie zawsze pozostaje darmowe, a premium to tylko dodatki."],
            ['Złota Liga na nowy sezon', "Do Złotej Ligi trafia 10 graczy z najwyższą sumą wpłat z ostatnich 12 miesięcy. Przy remisie decyduje wcześniejsza ostatnia wpłata.\n\nWolne miejsca zajmują boty, a rozgrywki toczą się systemem każdy z każdym."],
            ['Awanse i spadki', "Po zakończeniu sezonu z każdej ligi awansują 4 zespoły i 4 spadają. Dodatkowo czyścimy ligi z botów, nawet do 6 zamian.\n\nTrzy najlepsze zespoły lig trafiają do Ligi Mistrzów, Europy i Konferencji."],
            ['Nowy wygląd strony głównej', "Odświeżyliśmy stronę główną: newsy wyświetlają się w siatce, a banery mają barwy Kolejorza.\n\nDajcie znać, co myślicie, oceniając ten wpis kciukiem w górę albo w dół."],
            ['Liga podwórkowa w systemie szwajcarskim', "Liga podwórkowa gra systemem szwajcarskim: pierwsza runda według listy, kolejne według klasyfikacji.\n\nPrzy nieparzystej liczbie zespołów wolny zespół gra z wirtualnym Lechem, który strzela tyle goli co Lech w prawdziwym meczu."],
            ['Weryfikacja adresów e-mail', "Nowe konta potwierdzamy sześciocyfrowym kodem wysyłanym na e-mail. Jeśli kod nie dotarł, sprawdźcie folder spam.\n\nW razie problemów napiszcie do nas przez formularz w stopce."],
            ['Statystyki gracza', "W zakładce Statystyki znajdziecie nowe kafelki: optymistę, najlepszą kolejkę, liczbę skalpów i serie zwycięstw.\n\nGracze premium widzą dodatkowo bilans bezpośredni z rywalami."],
            ['Hall of Fame liczy się na bieżąco', "Punkty Hall of Fame za wygrane mecze i rundy pucharu dopisujemy po każdej rozliczonej kolejce.\n\nTytuły, awanse i trofea pojawiają się w gablocie po zakończeniu sezonu."],
            ['Przypomnienie o regulaminie', "Wynik zespołu to punkty za typ plus bonus ofensywny minus bonus defensywny rywala, nigdy mniej niż zero.\n\nCały regulamin w skrócie znajdziecie w zakładce Zasady gry."],
            ['Boty w ligach', "Zespoły bez właściciela prowadzą boty. Typują losowy wynik od 0:0 do 3:3 i nie odpowiadają na pytania.\n\nIm więcej graczy, tym mniej botów w wyższych ligach."],
            ['Nieaktywni gracze', "Pięć kolejek z rzędu bez typu oznacza, że od następnego sezonu zespół przejmie bot.\n\nAdmin może przywrócić konto, a gracz wraca wtedy do Ligi podwórkowej."],
            ['Start przygotowań do sezonu', "Ruszyły przygotowania do nowego sezonu. Lista przedsezonowa jest już budowana na podstawie wyników poprzednich rozgrywek.\n\nNowi gracze trafią do Ligi podwórkowej."],
            ['Witamy w LechTYPER!', "LechTYPER to gra typerska dla kibiców Kolejorza. W każdej kolejce typujecie wynik jednego meczu Lecha i odpowiadacie na pytania bonusowe.\n\nPowodzenia i do zobaczenia w tabelach!"],
            ['Szkic: plany na przerwę zimową', "Ten wpis to szkic i nie jest widoczny na stronie głównej.\n\nW przerwie zimowej planujemy turniej towarzyski i nowe trofea."],
        ];
    }
}
