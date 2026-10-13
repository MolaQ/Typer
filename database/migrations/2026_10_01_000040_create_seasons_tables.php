<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sezon i jego szkielet: kalendarz meczów Lecha, 9 kolejek (każda = jeden mecz Lecha) i lista przedsezonowa zespołów.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            // Numer jako liczba (4); zapis rzymski (IV) powstaje przy wyświetlaniu.
            $table->unsignedSmallInteger('number')->unique();
            // Hasło sezonu, np. "Jak Fenix z popiołów".
            $table->string('slogan', 120)->nullable();
            // Sponsor sezonu (opcjonalnie): nazwa, logo na dysku "public" i adres strony (logo w banerze do niej prowadzi).
            $table->string('sponsor_name', 80)->nullable();
            $table->string('sponsor_logo_path')->nullable();
            $table->string('sponsor_url')->nullable();
            // draft | approved | active | finished (App\Enums\SeasonStatus).
            $table->string('status', 20)->default('draft')->index();
            $table->timestamps();
        });

        // Kalendarz meczów Lecha (dashboard/lech-calendar), niezależny od sezonów: kolejka wybiera z niego mecz.
        // Pusty rywal = "mecz bez nazwy" tworzony przy zapisie sezonu (Season::createPlaceholderMatches).
        Schema::create('lech_matches', function (Blueprint $table) {
            $table->id();
            $table->string('opponent', 80);
            $table->boolean('is_home')->default(true);
            // Rozgrywki meczu Lecha: wartość App\Enums\MatchCompetition.
            $table->string('competition', 60)->default('league');
            // Termin może być jeszcze nieznany (np. runda pucharu bez daty). Czas polski (Europe/Warsaw).
            $table->dateTime('kickoff_at')->nullable()->index();
            // Przełożony: kolejka z tym meczem jest przełożona, typy zostają.
            $table->boolean('postponed')->default(false);
            $table->string('note', 120)->nullable();
            $table->timestamps();
        });

        // Kolejka sezonu = jeden prawdziwy mecz Lecha (regulamin, Kolejka). Wszystkie rozgrywki typują ten sam mecz.
        Schema::create('matchdays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            // Mecz z kalendarza. Dane meczu są kopiowane do kolejki (Matchday::assignMatch, LechMatch::syncMatchday).
            $table->foreignId('lech_match_id')->nullable()->constrained('lech_matches')->nullOnDelete();
            // Numer kolejki 1-9 (Matchday::PER_SEASON).
            $table->unsignedTinyInteger('number');
            // Kopia danych meczu: rywal, gospodarz, rozgrywki (App\Enums\MatchCompetition) i pierwszy gwizdek.
            $table->string('opponent', 80)->nullable();
            $table->boolean('is_home')->default(true);
            $table->string('competition', 60)->nullable();
            // Po pierwszym gwizdku typowanie jest zamknięte.
            $table->dateTime('kickoff_at')->nullable()->index();
            // planned | postponed | played (App\Enums\MatchdayStatus).
            $table->string('status', 20)->default('planned');
            // Wynik po 90 minutach (Lech : rywal), wpisuje admin w dashboard/results.
            $table->unsignedTinyInteger('lech_goals')->nullable();
            $table->unsignedTinyInteger('opponent_goals')->nullable();
            $table->timestamps();
            $table->unique(['season_id', 'number']);
        });

        // Lista przedsezonowa: kolejność zespołów w sezonie (regulamin, Rozgrywki). Liga wynika z pozycji:
        // 1-10 Ekstraklasa, 11-20 I liga ... 91-100 C klasa, od 101 Liga podwórkowa.
        Schema::create('season_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            // Pozycja na liście. Kolumna ze znakiem celowo: przy zamianie miejsc chwilowo ustawiamy -1,
            // żeby nie złamać unikalności. W Lidze podwórkowej mogą powstać luki w numeracji.
            $table->integer('position');
            // Właściciel: gracz albo bot (dokładnie jedno z dwóch). Po usunięciu konta miejsce zostaje bez gracza.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Bota nigdy nie usuwamy, klucz obcy blokuje przypadkowe skasowanie.
            $table->foreignId('bot_id')->nullable()->constrained('bots')->restrictOnDelete();
            // Miejsce z poprzedniego sezonu, z którego powstało to miejsce (lista budowana z wyników, BuildListFromPrevious).
            // Dzięki temu wiemy, kto awansował, nawet gdy gracza zastąpił bot.
            $table->foreignId('previous_id')->nullable()->constrained('season_teams')->nullOnDelete();
            $table->timestamps();
            $table->unique(['season_id', 'position']);
            // Gracz i bot występują na liście sezonu najwyżej raz (wiele NULL jest dozwolone).
            $table->unique(['season_id', 'user_id']);
            $table->unique(['season_id', 'bot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_teams');
        Schema::dropIfExists('matchdays');
        Schema::dropIfExists('lech_matches');
        Schema::dropIfExists('seasons');
    }
};
