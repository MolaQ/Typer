<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kadra Lecha (bez sztabu): zawodnicy, których gracze będą desygnować do gry (punkty za gole, asysty i czyste konta),
 * raport meczu kolejki (występy, gole) i ustawienia desygnowania.
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

        // Raport meczu kolejki (dashboard/results): kto zagrał, od której do której minuty, kartki.
        // Z tego Support\MatchReport liczy połowy, blokady, czyste konta i doświadczenie.
        Schema::create('match_appearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matchday_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lech_player_id')->constrained()->cascadeOnDelete();
            // Wyjściowa jedenastka (true) albo wejście z ławki (false).
            $table->boolean('started')->default(true);
            // Minuta wejścia z ławki (46 = od przerwy), dla wyjściowych null.
            $table->unsignedTinyInteger('on_minute')->nullable();
            // Minuta zejścia (zmiana albo czerwona kartka), null = do końca meczu.
            $table->unsignedTinyInteger('off_minute')->nullable();
            // Żółta kartka (druga żółta = czerwona, zaznaczamy wtedy czerwoną).
            $table->boolean('yellow')->default(false);
            $table->boolean('red')->default(false);
            $table->timestamps();
            $table->unique(['matchday_id', 'lech_player_id']);
        });

        // Gole meczu kolejki, także z dogrywki: strona (Lech albo rywal), minuta, strzelec i asystent z kadry Lecha.
        Schema::create('match_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matchday_id')->constrained()->cascadeOnDelete();
            // lech | opponent: kto zdobył bramkę.
            $table->string('side', 10);
            // Minuta gola (doliczony czas = 45 albo 90), null = jeszcze nie wpisana.
            $table->unsignedTinyInteger('minute')->nullable();
            // Gol Lecha: strzelec i asystent (null = samobój rywala albo nieznany).
            // Gol rywala: scorer_id = zawodnik Lecha, który strzelił samobója (null = zwykły gol rywala).
            $table->foreignId('scorer_id')->nullable()->constrained('lech_players')->nullOnDelete();
            $table->foreignId('assist_id')->nullable()->constrained('lech_players')->nullOnDelete();
            $table->timestamps();
            $table->index(['matchday_id', 'side']);
        });

        // Desygnowanie: zawodnicy wystawieni przez klub (gracz albo bot) w kolejce do jednego typu rozgrywek.
        // Każdy klub ma całą kadrę Lecha; dorobek zawodnika w klubie (gole, asysty, kartki, doświadczenie, blokady)
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
            $table->unsignedTinyInteger('minutes')->default(0);
            $table->unsignedTinyInteger('goals')->default(0);
            $table->unsignedTinyInteger('assists')->default(0);
            $table->unsignedTinyInteger('own_goals')->default(0);
            // Czyste konto bramkarza albo obrońcy (null = nie dotyczy: inna pozycja albo nie grał).
            $table->boolean('clean_sheet')->nullable();
            $table->boolean('yellow')->default(false);
            $table->boolean('red')->default(false);
            // Punkty ataku (gole + asysty) i obrony (czyste konto) dla klubu, doświadczenie i kolejki blokady z tego meczu.
            $table->unsignedTinyInteger('attack')->default(0);
            $table->unsignedTinyInteger('defense')->default(0);
            $table->unsignedTinyInteger('experience')->default(0);
            $table->unsignedTinyInteger('lock')->default(0);
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
        Schema::dropIfExists('match_goals');
        Schema::dropIfExists('match_appearances');
        Schema::dropIfExists('lech_players');
    }
};
