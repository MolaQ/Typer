<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Koniec domyślnego typu premium: pierwsze wejście na kolejkę zapisuje typ 0:0 każdemu graczowi.
     * Kolumna tips.is_default zostaje dla starych typów (historia, statystyki).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['default_tip_lech', 'default_tip_opponent']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('default_tip_lech')->nullable();
            $table->unsignedTinyInteger('default_tip_opponent')->nullable();
        });
    }
};
