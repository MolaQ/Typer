<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Złota Liga usunięta z gry: kasujemy jej rozgrywki (z uczestnikami i meczami), zestawy pytań,
     * dorobek w jej zestawie, trofea w Hall of Fame, ikonę trofeum i barwy. Wpłaty i premium zostają.
     */
    public function up(): void
    {
        $slots = DB::table('matchday_questions')->where('competition_type', 'golden')->pluck('id');
        DB::table('tip_answers')->whereIn('matchday_question_id', $slots)->delete();
        DB::table('matchday_questions')->whereIn('id', $slots)->delete();

        DB::table('team_scores')->where('question_set', 'golden')->delete();

        $competitions = DB::table('competitions')->where('type', 'golden')->pluck('id');
        DB::table('hall_of_fame_awards')->whereIn('competition_id', $competitions)->delete();
        DB::table('hall_of_fame_awards')->where('trophy', 'golden')->orWhere('kind', 'golden_title')->delete();
        DB::table('final_standings')->whereIn('competition_id', $competitions)->delete();
        DB::table('fixtures')->whereIn('competition_id', $competitions)->delete();
        DB::table('competition_entries')->whereIn('competition_id', $competitions)->delete();
        DB::table('competitions')->whereIn('id', $competitions)->delete();

        DB::table('trophy_icons')->where('key', 'golden')->delete();
        DB::table('competition_colors')->where('key', 'golden')->delete();
    }

    public function down(): void
    {
        // Usuniętych danych nie odtwarzamy.
    }
};
