<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Bank pytań tak/nie. Pytanie można wyłączyć (is_active), ale nie usunąć, jeśli było użyte.
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->string('text', 255)->unique();
            $table->string('side', 12); // offensive | defensive
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['side', 'is_active']);
        });

        // Zestaw pytań kolejki: dla każdego typu rozgrywek 5 ofensywnych i 5 defensywnych.
        Schema::create('matchday_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matchday_id')->constrained()->cascadeOnDelete();
            $table->string('competition_type', 20); // patrz App\Enums\CompetitionType
            $table->string('side', 12);
            $table->unsignedTinyInteger('position'); // 1-5
            $table->foreignId('question_id')->constrained()->restrictOnDelete();

            // Poprawna odpowiedź wpisuje admin po meczu (etap 11). null = jeszcze nieznana.
            $table->boolean('correct_answer')->nullable();
            $table->timestamps();

            // Jedno miejsce w zestawie i jedno pytanie w kolejce tylko raz (bez powtórek między typami).
            $table->unique(['matchday_id', 'competition_type', 'side', 'position'], 'mq_slot_unique');
            $table->unique(['matchday_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matchday_questions');
        Schema::dropIfExists('questions');
    }
};