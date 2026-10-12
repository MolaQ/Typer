<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kto naprawdę grał miejscem z listy w danej kolejce (gracz albo bot). Gracz, który w trakcie sezonu wskoczy
 * za bota, przejmuje wynik miejsca w tabeli i pucharze, ale nie statystyki, skalpy ani punkty Hall of Fame
 * z kolejek rozegranych przez bota (App\Support\Ownership).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_scores', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('season_team_id')->constrained()->nullOnDelete();
            $table->foreignId('bot_id')->nullable()->after('user_id')->constrained('bots')->nullOnDelete();
            $table->index(['user_id', 'matchday_id']);
            $table->index(['bot_id', 'matchday_id']);
        });

        // Dotychczasowe rozliczenia: właściciel miejsca z listy (lepszej informacji nie mamy).
        foreach (DB::table('season_teams')->get(['id', 'user_id', 'bot_id']) as $team) {
            DB::table('team_scores')->where('season_team_id', $team->id)->update(['user_id' => $team->user_id, 'bot_id' => $team->bot_id]);
        }
    }

    public function down(): void
    {
        Schema::table('team_scores', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'matchday_id']);
            $table->dropIndex(['bot_id', 'matchday_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropConstrainedForeignId('bot_id');
        });
    }
};
