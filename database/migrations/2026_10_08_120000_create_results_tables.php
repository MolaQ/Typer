<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Liga i Liga podwórkowa mają wspólny zestaw pytań (zapisany jako league).
        // Osobne zestawy podwórkowej z etapu 9 usuwamy razem z odpowiedziami (kaskada w tip_answers).
        DB::table('matchday_questions')->where('competition_type', 'swiss')->delete();

        // Typ bota na kolejkę: losowy wynik 0-3 : 0-3, bez odpowiedzi na pytania (regulamin, punkt 8).
        // Zapisujemy go, żeby ponowne przeliczenie kolejki dawało ten sam wynik.
        Schema::create('bot_tips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matchday_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_team_id')->constrained('season_teams')->cascadeOnDelete();
            $table->unsignedTinyInteger('lech_goals');
            $table->unsignedTinyInteger('opponent_goals');
            $table->timestamps();

            $table->unique(['matchday_id', 'season_team_id']);
        });

        // Dorobek zespołu w kolejce dla jednego zestawu pytań (league, cup, champions...).
        // Na tej podstawie liczymy wyniki meczów i kryteria tabeli. Przeliczenie kolejki nadpisuje wiersze.
        Schema::create('team_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matchday_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_team_id')->constrained('season_teams')->cascadeOnDelete();
            $table->string('question_set', 20); // patrz CompetitionType::questionSet()

            $table->boolean('has_tip')->default(false);
            $table->unsignedTinyInteger('tip_lech')->nullable();
            $table->unsignedTinyInteger('tip_opponent')->nullable();
            // Czas typu do remisów w pucharze (bot i brak typu = godzina meczu).
            $table->timestamp('tipped_at', 6)->nullable();

            // Punkty za typ 0-3 i ich składowe.
            $table->unsignedTinyInteger('tip_points')->default(0);
            $table->boolean('outcome_hit')->default(false);
            $table->boolean('diff_hit')->default(false);
            $table->boolean('exact_hit')->default(false);

            // Zestawy pytań: punkty po zerowaniu (zła odpowiedź zeruje zestaw danej strony).
            $table->unsignedTinyInteger('offense_bonus')->default(0);
            $table->unsignedTinyInteger('defense_bonus')->default(0);
            $table->boolean('offense_zeroed')->default(false);
            $table->boolean('defense_zeroed')->default(false);

            // Wynik ofensywny = punkty za typ + bonus ofensywny.
            $table->unsignedTinyInteger('offense')->default(0);

            $table->timestamps();

            $table->unique(['matchday_id', 'season_team_id', 'question_set'], 'team_scores_unique');
            $table->index(['season_team_id', 'question_set']);
        });

        // Wynik meczu w terminarzu. Puchar: przy remisie awansuje wcześniejszy typ ("po karnych").
        Schema::table('fixtures', function (Blueprint $table) {
            $table->unsignedTinyInteger('home_goals')->nullable()->after('away_entry_id');
            $table->unsignedTinyInteger('away_goals')->nullable()->after('home_goals');
            $table->foreignId('winner_entry_id')->nullable()->after('away_goals')
                ->constrained('competition_entries')->nullOnDelete();
            $table->boolean('decided_by_time')->default(false)->after('winner_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('fixtures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('winner_entry_id');
            $table->dropColumn(['home_goals', 'away_goals', 'decided_by_time']);
        });

        Schema::dropIfExists('team_scores');
        Schema::dropIfExists('bot_tips');
    }
};
