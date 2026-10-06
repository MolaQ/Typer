<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kolejka sezonu = jeden rzeczywisty mecz Lecha Poznań (regulamin, punkt 1).
        // Wszystkie rozgrywki sezonu (liga, puchar, Europa...) typują ten sam mecz.
        Schema::create('matchdays', function (Blueprint $table) {
            $table->id();

            // Usunięcie sezonu usuwa jego kolejki.
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();

            // Numer kolejki 1-9 (patrz Matchday::PER_SEASON).
            $table->unsignedTinyInteger('number');

            // Rywal Lecha. Puste, dopóki admin nie uzupełni kolejki.
            $table->string('opponent', 80)->nullable();
            $table->boolean('is_home')->default(true);

            // Z jakich rozgrywek jest mecz Lecha, np. "Ekstraklasa" (informacyjnie).
            $table->string('competition', 60)->nullable();

            // Godzina pierwszego gwizdka. Po niej typowanie jest zamknięte.
            $table->dateTime('kickoff_at')->nullable()->index();

            // planned | postponed | played (patrz App\Enums\MatchdayStatus).
            $table->string('status', 20)->default('planned');

            // Wynik wpisany przez admina po meczu (etap 11), po 90 minutach, Lech : rywal.
            $table->unsignedTinyInteger('lech_goals')->nullable();
            $table->unsignedTinyInteger('opponent_goals')->nullable();

            $table->timestamps();

            // Jedna kolejka o danym numerze w sezonie.
            $table->unique(['season_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matchdays');
    }
};
