<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kalendarz meczów Lecha (wstępny terminarz z panelu). Kolejka sezonu wybiera mecz z kalendarza
     * (matchdays.lech_match_id) i kopiuje jego dane (rywal, gospodarz, rozgrywki, termin), więc reszta
     * serwisu dalej czyta je z kolejki.
     */
    public function up(): void
    {
        Schema::create('lech_matches', function (Blueprint $table) {
            $table->id();
            $table->string('opponent', 80);
            $table->boolean('is_home')->default(true);
            // Wartość App\Enums\MatchCompetition.
            $table->string('competition', 60)->default('league');
            // Termin może być jeszcze nieznany (np. runda pucharu bez daty).
            $table->dateTime('kickoff_at')->nullable()->index();
            // Przełożony: kolejka z tym meczem czeka na nowy wybór, typy zostają.
            $table->boolean('postponed')->default(false);
            $table->string('note', 120)->nullable();
            $table->timestamps();
        });

        Schema::table('matchdays', function (Blueprint $table) {
            $table->foreignId('lech_match_id')->nullable()->after('season_id')->constrained('lech_matches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('matchdays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lech_match_id');
        });

        Schema::dropIfExists('lech_matches');
    }
};
