<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rozliczenie kolejek (dorobek zespołów) i tabele końcowe sezonu.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dorobek miejsca z listy w kolejce dla jednego typu rozgrywek (ScoreMatchday). Z niego liczymy wyniki meczów
        // i kryteria tabel. Przeliczenie kolejki nadpisuje wiersze. Wiersz powstaje tylko dla zestawów, w których zespół gra.
        Schema::create('team_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matchday_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_team_id')->constrained('season_teams')->cascadeOnDelete();
            // Kto grał tym miejscem w tej kolejce (Support\Ownership): statystyki, skalpy, H2H i nagrody idą do niego.
            // Przy ponownym przeliczeniu zostaje pierwszy właściciel.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bot_id')->nullable()->constrained('bots')->nullOnDelete();
            // Typ rozgrywek: CompetitionType::scoreSet() (league, cup, champions, legends).
            $table->string('score_set', 20);
            $table->boolean('has_tip')->default(false);
            $table->unsignedTinyInteger('tip_lech')->nullable();
            $table->unsignedTinyInteger('tip_opponent')->nullable();
            // Czas typu do wyświetlenia (bot i brak typu = godzina meczu).
            $table->timestamp('tipped_at', 6)->nullable();
            // Kopia tips.save_order do remisów w pucharach (null = bot albo brak typu: na końcu, potem rozstawienie).
            $table->unsignedBigInteger('tip_order')->nullable();
            // Punkty za typ 0-3 i ich składowe (regulamin, Punkty za typ).
            $table->unsignedTinyInteger('tip_points')->default(0);
            $table->boolean('outcome_hit')->default(false);
            $table->boolean('diff_hit')->default(false);
            $table->boolean('exact_hit')->default(false);
            // Bonus ataku i obrony (punkty desygnowanych zawodników; na razie 0, pytania usunięto).
            $table->unsignedTinyInteger('offense_bonus')->default(0);
            $table->unsignedTinyInteger('defense_bonus')->default(0);
            // Wynik ofensywny = punkty za typ + bonus ofensywny; gole w meczu = max(0, offense - defense_bonus rywala).
            $table->unsignedTinyInteger('offense')->default(0);
            $table->timestamps();
            $table->unique(['matchday_id', 'season_team_id', 'score_set'], 'team_scores_unique');
            $table->index(['season_team_id', 'score_set']);
            $table->index(['user_id', 'matchday_id']);
            $table->index(['bot_id', 'matchday_id']);
        });

        // Tabele końcowe sezonu (FinishSeason): podstawa listy na nowy sezon, Hall of Fame i historii zespołów.
        Schema::create('final_standings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_team_id')->constrained('season_teams')->cascadeOnDelete();
            // Właściciel miejsca na dzień zakończenia sezonu (lista może się później zmienić).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bot_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('place');
            // Kryteria tabeli w chwili zakończenia (punkty, bilans, bramki...).
            $table->json('stats')->nullable();
            $table->timestamps();
            $table->unique(['competition_id', 'season_team_id']);
            $table->index(['season_id', 'competition_id', 'place']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_standings');
        Schema::dropIfExists('team_scores');
    }
};
