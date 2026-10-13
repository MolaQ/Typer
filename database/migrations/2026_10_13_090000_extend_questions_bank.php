<?php

use App\Enums\MatchWeight;
use App\Models\QuestionCategory;
use App\Support\QuestionClassifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bank pytań 2.0 (etap 1): kategorie pytań oraz przy pytaniu „dotyczy”, rodzaje meczów Lecha, wagi meczów
     * i wymagane dane. Istniejące pytania dostają wstępne przypisanie po słowach w treści (QuestionClassifier).
     */
    public function up(): void
    {
        Schema::create('question_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->nullable()->unique();
            $table->string('name', 60)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('side')->constrained('question_categories')->nullOnDelete();
            $table->string('subject', 20)->default('lech')->after('category_id'); // App\Enums\QuestionSubject
            $table->json('match_types')->nullable()->after('subject'); // App\Enums\QuestionMatchType[]
            $table->json('weights')->nullable()->after('match_types'); // App\Enums\MatchWeight[]
            $table->string('data_level', 10)->default('basic')->after('weights'); // App\Enums\QuestionData
        });

        $now = now();
        $order = 0;
        foreach (QuestionCategory::DEFAULTS as $key => $name) {
            DB::table('question_categories')->insert(['key' => $key, 'name' => $name, 'sort_order' => ++$order, 'created_at' => $now, 'updated_at' => $now]);
        }

        $categories = DB::table('question_categories')->pluck('id', 'key');

        foreach (DB::table('questions')->get(['id', 'text']) as $question) {
            $data = QuestionClassifier::data($question->text);

            DB::table('questions')->where('id', $question->id)->update([
                'category_id' => $categories[QuestionClassifier::category($question->text)] ?? null,
                'subject' => QuestionClassifier::subject($question->text)->value,
                'match_types' => json_encode(QuestionClassifier::matchTypes($data)),
                'weights' => json_encode(MatchWeight::values()),
                'data_level' => $data->value,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['subject', 'match_types', 'weights', 'data_level']);
        });

        Schema::dropIfExists('question_categories');
    }
};
