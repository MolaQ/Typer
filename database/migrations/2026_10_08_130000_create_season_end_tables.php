<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Liga Legend: kolejka, po której zespół odpadł (null = nadal gra).
        Schema::table('competition_entries', function (Blueprint $table) {
            $table->unsignedTinyInteger('eliminated_round')->nullable()->after('seed');
        });

        // Zespół z poprzedniego sezonu, z którego powstało to miejsce (lista budowana z wyników).
        // Dzięki temu wiemy, kto np. awansował do Ligi Mistrzów, nawet gdy gracz został zastąpiony botem.
        Schema::table('season_teams', function (Blueprint $table) {
            $table->foreignId('previous_id')->nullable()->after('bot_id')
                ->constrained('season_teams')->nullOnDelete();
        });

        // Tabele końcowe sezonu (zapisywane przy zakończeniu): podstawa listy na nowy sezon i Hall of Fame.
        Schema::create('final_standings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_team_id')->constrained('season_teams')->cascadeOnDelete();
            // Kopia właściciela na dzień zakończenia (lista może się później zmienić).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bot_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('place');
            $table->json('stats')->nullable();
            $table->timestamps();

            $table->unique(['competition_id', 'season_team_id']);
            $table->index(['season_id', 'competition_id', 'place']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_standings');

        Schema::table('season_teams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_id');
        });

        Schema::table('competition_entries', function (Blueprint $table) {
            $table->dropColumn('eliminated_round');
        });
    }
};
