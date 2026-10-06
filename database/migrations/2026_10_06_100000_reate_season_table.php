<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();

            // Numer jako liczba (4); zapis rzymski (IV) powstaje przy wyświetlaniu.
            $table->unsignedSmallInteger('number')->unique();

            // Hasło sezonu, np. "Jak Fenix z popiołów".
            $table->string('slogan', 120)->nullable();

            // Sponsor (opcjonalnie): nazwa i ścieżka logo na dysku "public".
            $table->string('sponsor_name', 80)->nullable();
            $table->string('sponsor_logo_path')->nullable();

            // draft | active | finished (patrz App\Enums\SeasonStatus).
            $table->string('status', 20)->default('draft')->index();

            // Daty orientacyjne, na razie opcjonalne.
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};