<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hall of Fame (punkty i trofea) oraz ustawienia gry zmieniane w panelu: punktacja, ikony trofeów,
 * barwy rozgrywek i kolejność kwalifikacji do pucharów europejskich. Brak wiersza = wartość domyślna z kodu.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Punktacja Hall of Fame zmieniona w panelu (domyślne w App\Support\HallOfFame).
        Schema::create('hall_of_fame_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->decimal('value', 8, 2);
            $table->timestamps();
        });

        // Ikony trofeów do gabloty, klucz = Competition::trophyKey() albo nagroda indywidualna (TrophyIconsSeeder).
        Schema::create('trophy_icons', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            // Ścieżka na dysku "public".
            $table->string('path');
            $table->timestamps();
        });

        // Punkty i trofea Hall of Fame (AwardHallOfFame): mecze liczone na bieżąco po każdej kolejce,
        // tytuły, miejsca i nagrody indywidualne przy końcu sezonu.
        Schema::create('hall_of_fame_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competition_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('season_team_id')->nullable()->constrained('season_teams')->nullOnDelete();
            // Komu przypada nagroda: gracz albo bot (za mecze ten, kto grał daną kolejkę).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bot_id')->nullable()->constrained()->nullOnDelete();
            // Rodzaj nagrody, np. won, drawn, title, promotion, top_scorer, cup_winner (AwardHallOfFame).
            $table->string('kind', 32);
            // Klucz trofeum (HallOfFame::trophies()), gdy nagroda je daje.
            $table->string('trophy', 64)->nullable();
            $table->decimal('points', 8, 1)->default(0);
            // Szczegóły do wyświetlenia (kolejka, miejsce, etap...).
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'season_id']);
            $table->index(['bot_id', 'season_id']);
            $table->index('trophy');
        });

        // Barwy rozgrywek zmienione w panelu (domyślne w App\Support\CompetitionColors): gradient tła, akcent i tekst.
        Schema::create('competition_colors', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('bg1', 7);
            $table->string('bg2', 7);
            $table->string('accent', 7);
            $table->string('ink', 7);
            $table->timestamps();
        });

        // Kolejność tytułów na liście kwalifikacji do pucharów europejskich (EuropeanQualification::DEFAULT_ORDER).
        Schema::create('european_qualification_order', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->unsignedSmallInteger('position');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('european_qualification_order');
        Schema::dropIfExists('competition_colors');
        Schema::dropIfExists('hall_of_fame_awards');
        Schema::dropIfExists('trophy_icons');
        Schema::dropIfExists('hall_of_fame_settings');
    }
};
