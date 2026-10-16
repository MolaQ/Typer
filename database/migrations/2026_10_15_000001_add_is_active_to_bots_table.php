<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wyłączone boty (awanse i spadki z 15.10.2026): bot spoza 512 miejsc nowej listy pauzuje sezon,
 * ale zostaje w puli i może wejść w trakcie sezonu na miejsce zbanowanego gracza (Roster::release).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            // Czy bot jest na liście bieżącego sezonu (false = pauzuje, czeka w puli).
            $table->boolean('is_active')->default(true)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
