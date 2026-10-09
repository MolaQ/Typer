<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Typ gracza na wynik meczu Lecha w danej kolejce (jeden typ na gracza i kolejkę).
        // saved_at = moment ostatniej ZMIANY typu lub odpowiedzi (z mikrosekundami).
        // Rozstrzyga remisy w pucharze: kto wcześniej zapisał ostateczny typ, ten lepszy.
        Schema::create('tips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matchday_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('lech_goals');
            $table->unsignedTinyInteger('opponent_goals');
            $table->timestamp('saved_at', 6);
            $table->timestamps();

            $table->unique(['matchday_id', 'user_id']);
            $table->index(['matchday_id', 'saved_at']);
        });

        // Odpowiedzi gracza na pytania bonusowe (tak = true, nie = false).
        // Brak wiersza = brak odpowiedzi. Odpowiedzi dotyczą miejsc w zestawach (matchday_questions).
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
    }

    public function down(): void
    {
        Schema::dropIfExists('tip_answers');
        Schema::dropIfExists('tips');
    }
};
