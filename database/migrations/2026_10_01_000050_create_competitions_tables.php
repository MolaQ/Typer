<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rozgrywki sezonu (ligi, Liga podwórkowa, Puchar Polski, puchary europejskie, Liga Legend), ich sponsorzy,
 * uczestnicy z rozstawieniem i terminarz z wynikami meczów.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Sponsorzy rozgrywek (dashboard/sponsors): każde rozgrywki sezonu mogą mieć swojego sponsora.
        Schema::create('sponsors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('url')->nullable();
            // Logo na dysku "public", katalog sponsors.
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });

        // Rozgrywki, np. "Ekstraklasa" (typ league, tier 1). Powstają przy zatwierdzeniu sezonu (ApproveSeason).
        Schema::create('competitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            // App\Enums\CompetitionType: league, swiss, cup, champions, europa, conference, legends.
            $table->string('type', 20);
            // Poziom ligi 1-10 (tylko ligi).
            $table->unsignedTinyInteger('tier')->nullable();
            $table->string('name');
            $table->foreignId('sponsor_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['season_id', 'type', 'tier']);
        });

        // Uczestnik rozgrywek = miejsce z listy sezonu + rozstawienie (seed 1 = najwyżej na liście).
        Schema::create('competition_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_team_id')->constrained('season_teams')->cascadeOnDelete();
            $table->unsignedSmallInteger('seed');
            // Kolejka, w której zespół odpadł (Liga Legend, puchary europejskie). null = nadal gra.
            // Zespół gra w kolejce N, gdy eliminated_round jest null albo >= N.
            $table->unsignedTinyInteger('eliminated_round')->nullable();
            $table->timestamps();
            $table->unique(['competition_id', 'season_team_id']);
            $table->unique(['competition_id', 'seed']);
        });

        // Mecz w terminarzu. W pucharach i Lidze podwórkowej zespół może być jeszcze nieznany (miejsce w drabince)
        // albo rywalem jest wirtualny "Lech Poznań" (wolny los), dlatego mecz opisują miejsca, a zespoły dochodzą później.
        Schema::create('fixtures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            // Kolejka 1-9.
            $table->unsignedTinyInteger('round');
            // Miejsca: w lidze 1-10, w pucharze 1-512, w Lidze podwórkowej numer z listy.
            // Gość bez miejsca i bez zespołu to wirtualny rywal. Liga Legend gra bez miejsc.
            $table->unsignedSmallInteger('home_seat')->nullable();
            $table->unsignedSmallInteger('away_seat')->nullable();
            // Zespoły dopisujemy, gdy są znane (w pucharze od rundy 2 po wynikach).
            $table->foreignId('home_entry_id')->nullable()->constrained('competition_entries')->cascadeOnDelete();
            $table->foreignId('away_entry_id')->nullable()->constrained('competition_entries')->cascadeOnDelete();
            // Wynik meczu (ScoreMatchday).
            $table->unsignedTinyInteger('home_goals')->nullable();
            $table->unsignedTinyInteger('away_goals')->nullable();
            // Zwycięzca w meczach pucharowych. Remis rozstrzyga wcześniejszy zapis typu ("po karnych", decided_by_time).
            $table->foreignId('winner_entry_id')->nullable()->constrained('competition_entries')->nullOnDelete();
            $table->boolean('decided_by_time')->default(false);
            $table->timestamps();
            $table->index(['competition_id', 'round']);
            $table->unique(['competition_id', 'round', 'home_seat']);
            $table->unique(['competition_id', 'round', 'away_seat']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixtures');
        Schema::dropIfExists('competition_entries');
        Schema::dropIfExists('competitions');
        Schema::dropIfExists('sponsors');
    }
};
