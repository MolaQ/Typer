<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rozgrywki sezonu, np. "Ekstraklasa" (typ league, tier 1).
        Schema::create('competitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedTinyInteger('tier')->nullable(); // poziom ligi 1-10
            $table->string('name');
            $table->timestamps();

            $table->unique(['season_id', 'type', 'tier']);
        });

        // Uczestnik rozgrywek = zespół z listy przedsezonowej + jego rozstawienie (seed).
        // Seed 1 to najwyżej na liście w danej lidze i to on jest gospodarzem w parze.
        Schema::create('competition_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_team_id')->constrained('season_teams')->cascadeOnDelete();
            $table->unsignedSmallInteger('seed');
            $table->timestamps();

            $table->unique(['competition_id', 'season_team_id']);
            $table->unique(['competition_id', 'seed']);
        });

        // Mecz w terminarzu: kto z kim w której kolejce (1-9).
        // Wyniki i punkty dojdą w etapie 11 (osobna migracja).
        Schema::create('fixtures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('round');
            $table->foreignId('home_entry_id')->constrained('competition_entries')->cascadeOnDelete();
            $table->foreignId('away_entry_id')->constrained('competition_entries')->cascadeOnDelete();
            $table->timestamps();

            // Zespół gra w kolejce tylko raz (jako gospodarz albo gość).
            $table->unique(['competition_id', 'round', 'home_entry_id']);
            $table->unique(['competition_id', 'round', 'away_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixtures');
        Schema::dropIfExists('competition_entries');
        Schema::dropIfExists('competitions');
    }
};
