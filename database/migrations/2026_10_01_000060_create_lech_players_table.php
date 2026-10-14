<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kadra Lecha (bez sztabu): zawodnicy, których gracze będą desygnować do gry (punkty za gole, asysty i czyste konta),
 * raport meczu (występy) i ustawienia desygnowania.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lech_players', function (Blueprint $table) {
            $table->id();
            // Imię i nazwisko.
            $table->string('name', 80)->unique();
            $table->unsignedTinyInteger('number')->nullable();
            // App\Enums\PlayerPosition: goalkeeper | defender | midfielder | forward.
            $table->string('position', 20);
            // Wychowanek Akademii Lecha.
            $table->boolean('is_academy')->default(false);
            // App\Enums\PlayerStatus: regular | rotation | reserve | excluded.
            $table->string('status', 20)->default('regular')->index();
            $table->timestamps();
        });

        // Raport meczu Lecha (dashboard/results dla kolejki, kalendarz Lecha dla meczów archiwalnych): kto zagrał,
        // kiedy wszedł i zszedł (bez minut, punkty osi meczu Support\MatchReport::SLOTS), gole, asysty, samobóje, kartki.
        // Kolumny wyliczane (czyste konto, czas gry, blokada, doświadczenie) zapisuje MatchReport::store po każdej zmianie.
        Schema::create('match_appearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lech_match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lech_player_id')->constrained()->cascadeOnDelete();
            // Wyjściowa jedenastka (true) albo wejście z ławki (false).
            $table->boolean('started')->default(true);
            // Wejście i zejście na osi meczu: 0 = początek, 1 = w 1. połowie, 2 = przerwa (45), 3 = w 2. połowie, 4 = koniec 90 minut,
            // 5 = w 1. połowie dogrywki, 6 = przerwa dogrywki (105), 7 = w 2. połowie dogrywki, 8 = koniec dogrywki (120).
            $table->unsignedTinyInteger('on_slot')->nullable()->default(0);
            $table->unsignedTinyInteger('off_slot')->default(4);
            // Gole (także w serii rzutów karnych), asysty i samobóje.
            $table->unsignedTinyInteger('goals')->default(0);
            $table->unsignedTinyInteger('assists')->default(0);
            $table->unsignedTinyInteger('own_goals')->default(0);
            // Żółte kartki (0, 1 albo 2 = dwie żółte) i czerwona.
            $table->unsignedTinyInteger('yellow')->default(0);
            $table->boolean('red')->default(false);
            // Wyliczane: czyste konto (bramkarz i obrońca, null = nie dotyczy), czas gry w połowach (np. 1,5), godziny odpoczynku, doświadczenie.
            $table->boolean('clean_sheet')->nullable();
            $table->decimal('play_time', 3, 1)->default(0);
            $table->unsignedSmallInteger('rest_hours')->default(0);
            $table->decimal('experience', 4, 1)->default(0);
            $table->timestamps();
            $table->unique(['lech_match_id', 'lech_player_id']);
        });

        // Desygnowanie: zawodnicy wystawieni przez klub (gracz albo bot) w kolejce do jednego typu rozgrywek.
        // Każdy klub ma całą kadrę Lecha; dorobek zawodnika w klubie (gole, asysty, kartki, doświadczenie, odpoczynek)
        // liczy się tylko z kolejek, w których klub go wystawił (Support\Squad).
        Schema::create('designated_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matchday_id')->constrained()->cascadeOnDelete();
            // Klub: gracz albo bot (dokładnie jedno z dwóch).
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->nullable()->constrained('bots')->cascadeOnDelete();
            // Typ rozgrywek (CompetitionType::scoreSet): league | cup | champions | legends.
            $table->string('score_set', 20);
            $table->foreignId('lech_player_id')->constrained()->cascadeOnDelete();
            // Rozliczenie przy przeliczeniu kolejki (ScoreMatchday). counted: null = jeszcze nie, false = przepadł (blokada), true = liczy się.
            $table->boolean('counted')->nullable();
            // Czas gry w połowach (cała połowa 1, część 0,5).
            $table->decimal('play_time', 3, 1)->default(0);
            $table->unsignedTinyInteger('goals')->default(0);
            $table->unsignedTinyInteger('assists')->default(0);
            $table->unsignedTinyInteger('own_goals')->default(0);
            // Czyste konto bramkarza albo obrońcy (null = nie dotyczy: inna pozycja albo nie grał).
            $table->boolean('clean_sheet')->nullable();
            $table->unsignedTinyInteger('yellow')->default(0);
            $table->boolean('red')->default(false);
            // Punkty ataku (gole + asysty) i obrony (czyste konto) dla klubu, doświadczenie i godziny odpoczynku z tego meczu.
            $table->unsignedTinyInteger('attack')->default(0);
            $table->unsignedTinyInteger('defense')->default(0);
            $table->decimal('experience', 4, 1)->default(0);
            $table->unsignedSmallInteger('rest_hours')->default(0);
            $table->timestamps();
            // Zawodnik w kolejce tylko w jednym typie rozgrywek danego klubu.
            $table->unique(['matchday_id', 'user_id', 'lech_player_id']);
            $table->unique(['matchday_id', 'bot_id', 'lech_player_id']);
        });

        // Ustawienia desygnowania (Support\Designation, domyślne w kodzie), klucz i wartość.
        Schema::create('designation_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->decimal('value', 8, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('designation_settings');
        Schema::dropIfExists('designated_players');
        Schema::dropIfExists('match_appearances');
        Schema::dropIfExists('lech_players');
    }
};
