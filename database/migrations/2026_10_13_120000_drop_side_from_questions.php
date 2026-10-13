<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jeden worek pytań: pytanie w banku (i propozycja gracza) nie ma już strony. Stronę (atak albo obrona)
 * dostaje dopiero przy losowaniu kolejki, w miejscu zestawu (matchday_questions.side zostaje).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex(['side', 'is_active']);
            $table->dropColumn('side');
            $table->index('is_active');
        });

        Schema::table('question_proposals', function (Blueprint $table) {
            $table->dropColumn('side');
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->string('side', 12)->default('offensive')->after('text');
            $table->index(['side', 'is_active']);
        });

        Schema::table('question_proposals', function (Blueprint $table) {
            $table->string('side', 20)->nullable()->after('text');
        });
    }
};
