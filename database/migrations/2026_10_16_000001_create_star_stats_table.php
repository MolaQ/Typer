<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gwiazdy gry (16.10.2026): gotowe sumy zawodników Lecha w drużynach graczy (bez botów), przeliczane po każdej kolejce
 * (Support\GameStars::rebuild). Liczenie z designated_players przy każdym wejściu na stronę było zbyt wolne
 * przy dziesiątkach tysięcy par drużyna + zawodnik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('star_stats', function (Blueprint $table) {
            $table->id();
            // Sezon (id); 0 = wszystkie sezony razem.
            $table->unsignedBigInteger('season_id');
            // Drużyna gracza, który wystawiał zawodnika.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Zawodnik Lecha.
            $table->foreignId('lech_player_id')->constrained()->cascadeOnDelete();
            // Pozycja zawodnika (kopia z lech_players do filtra).
            $table->string('position', 20);
            // Występy: mecze, w których drużyna wystawiła zawodnika (Liga Legend: każdy pojedynek).
            $table->unsignedInteger('fielded')->default(0);
            // Występy, w których zawodnik grał w prawdziwym meczu.
            $table->unsignedInteger('played')->default(0);
            // Gole, asysty, samobóje, czyste konta.
            $table->unsignedInteger('goals')->default(0);
            $table->unsignedInteger('assists')->default(0);
            $table->unsignedInteger('own_goals')->default(0);
            $table->unsignedInteger('clean_sheets')->default(0);
            // Punkty ataku, obrony i ich suma (dorobek).
            $table->decimal('attack', 8, 1)->default(0);
            $table->decimal('defense', 8, 1)->default(0);
            $table->decimal('contribution', 8, 1)->default(0);
            // Doświadczenie zebrane w drużynie.
            $table->decimal('experience', 8, 1)->default(0);
            // Kartki.
            $table->unsignedInteger('yellow')->default(0);
            $table->unsignedInteger('red')->default(0);
            // Wygrane i rozegrane mecze drużyny, w których zawodnik był wystawiony, oraz procent wygranych.
            $table->unsignedInteger('won')->default(0);
            $table->unsignedInteger('games')->default(0);
            $table->decimal('win_pct', 4, 1)->nullable();
            $table->unique(['season_id', 'user_id', 'lech_player_id']);
            $table->index(['season_id', 'contribution']);
            $table->index(['season_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('star_stats');
    }
};
