<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            // Adres strony sponsora (logo na stronie głównej będzie do niej prowadzić).
            $table->string('sponsor_url')->nullable()->after('sponsor_logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->dropColumn('sponsor_url');
        });
    }
};
