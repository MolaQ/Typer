<?php

use App\Models\QuestionCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bank pytań bonusowych tak/nie (jeden worek dla ataku i obrony), zestawy pytań kolejek i propozycje graczy.
 * Projekt banku: /mnt/project-files/notes/baza-pytan-2.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Kategorie pytań (gole, kartki, rzuty rożne...). Klucz łączy kategorię z automatem (QuestionClassifier).
        Schema::create('question_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->nullable()->unique();
            $table->string('name', 60)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Bank pytań. Pytanie nie ma strony: atak albo obronę dostaje przy losowaniu kolejki (DrawQuestions).
        // Użyte pytanie można wyłączyć (is_active), ale nie usunąć.
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->string('text', 255)->unique();
            $table->foreignId('category_id')->nullable()->constrained('question_categories')->nullOnDelete();
            // Czego dotyczy pytanie: App\Enums\QuestionSubject (Lech, rywal, mecz, zawodnik, sztab...).
            $table->string('subject', 20)->default('lech');
            // Rodzaje meczów Lecha, do których pasuje: lista App\Enums\QuestionMatchType (liga, PP, Europa, sparing).
            $table->json('match_types')->nullable();
            // Waga meczu: lista App\Enums\MatchWeight (łatwy, wyrównany, trudny).
            $table->json('weights')->nullable();
            // Dane potrzebne do rozstrzygnięcia: App\Enums\QuestionData (podstawowe / statystyki).
            $table->string('data_level', 10)->default('basic');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // Zestaw pytań kolejki (regulamin, Pytania): dla każdego zestawu rozgrywek 5 miejsc w ataku i 5 w obronie.
        Schema::create('matchday_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matchday_id')->constrained()->cascadeOnDelete();
            // Zestaw: App\Enums\CompetitionType::questionSet() (league, cup, champions, legends).
            $table->string('competition_type', 20);
            // Strona miejsca w zestawie: offensive | defensive (App\Enums\QuestionSide).
            $table->string('side', 12);
            // Miejsce 1-5 (MatchdayQuestion::PER_SIDE).
            $table->unsignedTinyInteger('position');
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            // Poprawną odpowiedź wpisuje admin po meczu. null = jeszcze nieznana.
            $table->boolean('correct_answer')->nullable();
            $table->timestamps();
            // Jedno pytanie na miejsce i bez powtórek pytań w kolejce (także między zestawami).
            $table->unique(['matchday_id', 'competition_type', 'side', 'position'], 'mq_slot_unique');
            $table->unique(['matchday_id', 'question_id']);
        });

        // Propozycje graczy (formularz w stopce i na stronie głównej): pytania do banku i nazwy botów.
        Schema::create('question_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // question | bot_name (QuestionProposal::QUESTION, BOT_NAME).
            $table->string('kind', 20)->default('question')->index();
            $table->string('text', 255);
            // pending | accepted | rejected.
            $table->string('status', 20)->default('pending')->index();
            $table->string('reject_reason', 255)->nullable();
            // Po akceptacji: pytanie dodane do banku albo bot, który dostał nazwę.
            $table->foreignId('question_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        // Domyślne kategorie (QuestionCategory::DEFAULTS), potrzebne seederowi pytań i automatowi kategorii.
        $now = now();
        $order = 0;
        foreach (QuestionCategory::DEFAULTS as $key => $name) {
            DB::table('question_categories')->insert(['key' => $key, 'name' => $name, 'sort_order' => ++$order, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('question_proposals');
        Schema::dropIfExists('matchday_questions');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('question_categories');
    }
};
