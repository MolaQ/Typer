<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Propozycje graczy obejmują też nazwy botów: rodzaj propozycji, bot, który dostał nazwę, i typ pytania tylko dla pytań.
        Schema::table('question_proposals', function (Blueprint $table) {
            $table->string('kind', 20)->default('question')->after('user_id')->index(); // question / bot_name
            $table->foreignId('bot_id')->nullable()->after('question_id')->constrained()->nullOnDelete();
            $table->string('side', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('question_proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bot_id');
            $table->dropColumn('kind');
        });
    }
};
