<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dotychczasowe boty miały tylko numer, bez nazwy z tabeli bots.
        // Usuwamy te wiersze, a listę uzupełnisz przyciskiem w panelu (brakujące miejsca
        // zajmą już nazwane boty). Wiersze z graczami zostają.
        DB::table('season_teams')->whereNull('user_id')->delete();

        Schema::table('season_teams', function (Blueprint $table) {
            // Bot nigdy nie jest usuwany, więc klucz obcy blokuje przypadkowe skasowanie.
            $table->foreignId('bot_id')->nullable()->after('user_id')->constrained('bots')->restrictOnDelete();

            // Ten sam bot może wystąpić w sezonie tylko raz.
            $table->unique(['season_id', 'bot_id']);

            $table->dropColumn('bot_number');
        });
    }

    public function down(): void
    {
        Schema::table('season_teams', function (Blueprint $table) {
            $table->unsignedSmallInteger('bot_number')->nullable();

            $table->dropUnique(['season_id', 'bot_id']);
            $table->dropConstrainedForeignId('bot_id');
        });
    }
};
