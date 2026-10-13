<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kadra Lecha (bez sztabu): zawodnicy, których gracze będą desygnować do gry (punkty za gole, asysty i czyste konta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lech_players', function (Blueprint $table) {
            $table->id();
            // Imię i nazwisko.
            $table->string('name', 80)->unique();
            $table->unsignedTinyInteger('number')->nullable();
            // App\Enums\PlayerPosition: goalkeeper | defender | midfielder | forward.
            $table->string('position', 20);
            // Wychowanek Akademii Lecha.
            $table->boolean('is_academy')->default(false);
            // Narodowość po polsku, np. „Polska”, „Szwecja”.
            $table->string('nationality', 40)->nullable();
            // App\Enums\PlayerStatus: regular | rotation | reserve | excluded.
            $table->string('status', 20)->default('regular')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lech_players');
    }
};
