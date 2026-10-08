<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wartości punktacji Hall of Fame zmieniane w panelu (brak wiersza = wartość domyślna z App\Support\HallOfFame).
        Schema::create('hall_of_fame_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->decimal('value', 8, 2);
            $table->timestamps();
        });

        // Ikony trofeów do gabloty (mistrzostwo każdego poziomu, król strzelców, puchary...).
        Schema::create('trophy_icons', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('path');
            $table->timestamps();
        });

        // Punkty i trofea Hall of Fame za zakończony sezon (przeliczane od nowa przy każdym przeliczeniu sezonu).
        Schema::create('hall_of_fame_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competition_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('season_team_id')->nullable()->constrained('season_teams')->nullOnDelete();
            // Właściciel na dzień zakończenia sezonu: gracz albo bot (historia botów w przyszłości).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 32);
            $table->string('trophy', 64)->nullable();
            $table->decimal('points', 8, 1)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'season_id']);
            $table->index(['bot_id', 'season_id']);
            $table->index('trophy');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_of_fame_awards');
        Schema::dropIfExists('trophy_icons');
        Schema::dropIfExists('hall_of_fame_settings');
    }
};
