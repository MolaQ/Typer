<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lista przedsezonowa: kolejność zespołów w sezonie (regulamin, punkt 2).
        // Liga wynika z pozycji: 1-10 Ekstraklasa, 11-20 I liga ... 91-100 C klasa,
        // od 101 liga podwórkowa. Dzięki temu nie trzymamy ligi w osobnej kolumnie.
        Schema::create('season_teams', function (Blueprint $table) {
            $table->id();

            $table->foreignId('season_id')->constrained()->cascadeOnDelete();

            // Pozycja na liście. Kolumna ze znakiem celowo: przy zamianie miejsc
            // chwilowo ustawiamy -1, żeby nie złamać unikalności.
            // W lidze podwórkowej mogą powstać luki w numeracji, co nie szkodzi.
            $table->integer('position');

            // Właściciel zespołu. Pusty = bot. Po usunięciu konta zespół zostaje
            // na liście jako bot (regulamin: zespół bez właściciela to bot).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Numer bota w obrębie sezonu ("Bot 007"). Tylko dla botów.
            $table->unsignedSmallInteger('bot_number')->nullable();

            $table->timestamps();

            $table->unique(['season_id', 'position']);
            // Gracz występuje na liście sezonu najwyżej raz (wiele NULL jest dozwolone).
            $table->unique(['season_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_teams');
    }
};
