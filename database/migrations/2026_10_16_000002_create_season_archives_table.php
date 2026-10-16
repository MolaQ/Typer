<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archiwum policzonych danych zakończonych sezonów (szybkość, 16.10.2026): zakończony sezon już się nie zmienia,
 * więc ciężkie zestawienia (np. statystyki wszystkich graczy sezonu) liczymy raz i czytamy gotowe (Support\SeasonArchive).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete(); // sezon, którego dotyczą dane
            $table->string('key', 80); // nazwa zestawienia, np. player-stats
            $table->longText('payload'); // policzone dane w JSON (same liczby, teksty i tablice)
            $table->timestamps();

            $table->unique(['season_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_archives');
    }
};
