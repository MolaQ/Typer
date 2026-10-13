<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Typy graczy (wynik meczu Lecha i odpowiedzi na pytania), kolejność zapisów do remisów oraz typy botów.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Typ gracza na kolejkę: jeden na wszystkie rozgrywki (regulamin, Kolejka), Lech : rywal po 90 minutach.
        // Pierwsze otwarcie zakładki Typowanie zapisuje 0:0 (ensureTip), potem gracz go zmienia.
        Schema::create('tips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matchday_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('lech_goals');
            $table->unsignedTinyInteger('opponent_goals');
            // Chwila ostatniej zmiany typu lub odpowiedzi (z mikrosekundami), do wyświetlania.
            $table->timestamp('saved_at', 6);
            // Numer ostatniego zapisu z tip_saves: rozstrzyga remisy w pucharach (mniejszy = wcześniej).
            $table->unsignedBigInteger('save_order')->nullable()->index();
            $table->timestamps();
            $table->unique(['matchday_id', 'user_id']);
            $table->index(['matchday_id', 'saved_at']);
        });

        // Dziennik zapisów typów: każdy zapis (nowy typ albo zmiana) dostaje kolejny numer z autoinkrementacji.
        // Numer jest ściśle rosnący i nie powtarza się nawet przy zapisach w tej samej chwili.
        Schema::create('tip_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matchday_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at', 6)->nullable();
            $table->index(['matchday_id', 'user_id']);
        });

        // Odpowiedzi gracza na pytania bonusowe (tak = true, nie = false). Brak wiersza = brak odpowiedzi.
        // Odpowiedź dotyczy miejsca w zestawie kolejki; po odpadnięciu z rozgrywek zostaje (statystyka pytań).
        Schema::create('tip_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matchday_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('matchday_question_id')->constrained()->cascadeOnDelete();
            $table->boolean('answer');
            $table->timestamps();
            $table->unique(['user_id', 'matchday_question_id']);
            $table->index(['matchday_id', 'user_id']);
        });

        // Typ bota na kolejkę: losowy wynik 0-3 : 0-3, bez pytań (regulamin, Boty).
        // Zapisany, żeby ponowne przeliczenie kolejki dawało ten sam wynik.
        Schema::create('bot_tips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matchday_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_team_id')->constrained('season_teams')->cascadeOnDelete();
            $table->unsignedTinyInteger('lech_goals');
            $table->unsignedTinyInteger('opponent_goals');
            $table->timestamps();
            $table->unique(['matchday_id', 'season_team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_tips');
        Schema::dropIfExists('tip_answers');
        Schema::dropIfExists('tip_saves');
        Schema::dropIfExists('tips');
    }
};
