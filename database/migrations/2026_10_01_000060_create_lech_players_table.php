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
            // Narodowość po polsku, np. „Polska”, „Szwecja”.
            $table->string('nationality', 40)->nullable();
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
        Schema::dropIfExists('match_goals');
        Schema::dropIfExists('match_appearances');
        Schema::dropIfExists('lech_players');
    }
};
