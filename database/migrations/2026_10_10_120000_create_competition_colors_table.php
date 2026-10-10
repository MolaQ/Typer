<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Barwy rozgrywek zmienione w panelu (brak wiersza = barwy domyślne z App\Support\CompetitionColors).
        Schema::create('competition_colors', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('bg1', 7);
            $table->string('bg2', 7);
            $table->string('accent', 7);
            $table->string('ink', 7);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_colors');
    }
};
