<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank pytań 2.0, etap 2: kadra Lecha i szablony pytań o zawodników. Z szablonu („Czy :zawodnik dostanie żółtą kartkę?”)
 * powstaje zwykłe pytanie w banku dla każdego pasującego zawodnika (App\Support\QuestionTemplates).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Kadra Lecha (bez sztabu): dane do pytań o zawodników, a w kolejnych etapach do szczegółów meczu i typu zawodnika.
        Schema::create('lech_players', function (Blueprint $table) {
            $table->id();
            // Imię i nazwisko, wstawiane w miejsce :zawodnik w szablonach.
            $table->string('name', 80)->unique();
            $table->unsignedTinyInteger('number')->nullable();
            // App\Enums\PlayerPosition: goalkeeper | defender | midfielder | forward.
            $table->string('position', 20);
            // Wychowanek Akademii Lecha (pytania „Czy wychowanek strzeli gola?”, rozstrzygane w etapie 4).
            $table->boolean('is_academy')->default(false);
            // Narodowość po polsku, np. „Polska”, „Szwecja” (pytania o narodowości).
            $table->string('nationality', 40)->nullable();
            // App\Enums\PlayerStatus: regular | rotation | reserve | excluded.
            $table->string('status', 20)->default('regular')->index();
            $table->timestamps();
        });

        // Szablony pytań o zawodników. Znacznik :zawodnik zastępuje nazwisko.
        Schema::create('question_templates', function (Blueprint $table) {
            $table->id();
            $table->string('text', 255)->unique();
            $table->foreignId('category_id')->nullable()->constrained('question_categories')->nullOnDelete();
            // Pozycje, dla których powstają pytania (lista App\Enums\PlayerPosition). Pusta = wszystkie.
            $table->json('positions')->nullable();
            // Kopiowane do pytań: rodzaje meczów, wagi i wymagane dane (jak w questions).
            $table->json('match_types')->nullable();
            $table->json('weights')->nullable();
            $table->string('data_level', 10)->default('basic');
            // Wyłączony szablon wyłącza wszystkie swoje pytania.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('questions', function (Blueprint $table) {
            // Pytanie z szablonu: szablon i zawodnik. Usunięcie szablonu zostawia pytanie (bez powiązania);
            // zawodnika z pytaniami użytymi w kolejkach nie da się usunąć (można go wyłączyć z losowania).
            $table->foreignId('question_template_id')->nullable()->after('category_id')->constrained()->nullOnDelete();
            $table->foreignId('lech_player_id')->nullable()->after('question_template_id')->constrained()->restrictOnDelete();
            // true = utworzone automatycznie (zawodnik gra regularnie): zmiana statusu zawodnika je włącza i wyłącza.
            // false = dodane ręcznie przez admina dla dowolnego zawodnika: status ich nie zmienia.
            $table->boolean('is_auto')->default(false)->after('lech_player_id');
            $table->unique(['question_template_id', 'lech_player_id']);
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropUnique(['question_template_id', 'lech_player_id']);
            $table->dropConstrainedForeignId('lech_player_id');
            $table->dropConstrainedForeignId('question_template_id');
            $table->dropColumn('is_auto');
        });

        Schema::dropIfExists('question_templates');
        Schema::dropIfExists('lech_players');
    }
};
