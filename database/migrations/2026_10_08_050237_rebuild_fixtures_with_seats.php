<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Puchar i liga szwajcarska potrzebują meczów, w których zespół nie jest jeszcze znany
        // (miejsce w drabince) albo rywalem jest wirtualny przeciwnik. Tabela fixtures z etapu 8a
        // miała na sztywno oba zespoły, więc budujemy ją od nowa.
        Schema::dropIfExists('fixtures');

        // Dotychczasowe rozgrywki (tylko 10 lig z etapu 8a) powstaną ponownie przy zatwierdzeniu.
        DB::table('competition_entries')->delete();
        DB::table('competitions')->delete();
        DB::table('seasons')->where('status', 'approved')->update(['status' => 'draft']);

        Schema::create('fixtures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('round');

            // Miejsca (rozstawienie): w lidze numer 1-10, w pucharze 1-512, w lidze szwajcarskiej numer z listy.
            // Gość bez miejsca i zespołu to wirtualny rywal (wolny los w lidze szwajcarskiej).
            $table->unsignedSmallInteger('home_seat')->nullable();
            $table->unsignedSmallInteger('away_seat')->nullable();

            // Zespoły dopisujemy, gdy są znane (w pucharze od rundy 2 po wynikach).
            $table->foreignId('home_entry_id')->nullable()->constrained('competition_entries')->cascadeOnDelete();
            $table->foreignId('away_entry_id')->nullable()->constrained('competition_entries')->cascadeOnDelete();

            $table->timestamps();

            $table->index(['competition_id', 'round']);
            $table->unique(['competition_id', 'round', 'home_seat']);
            $table->unique(['competition_id', 'round', 'away_seat']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixtures');

        Schema::create('fixtures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('round');
            $table->foreignId('home_entry_id')->constrained('competition_entries')->cascadeOnDelete();
            $table->foreignId('away_entry_id')->constrained('competition_entries')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['competition_id', 'round', 'home_entry_id']);
            $table->unique(['competition_id', 'round', 'away_entry_id']);
        });
    }
};
