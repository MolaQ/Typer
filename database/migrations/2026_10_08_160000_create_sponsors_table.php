<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Sponsorzy rozgrywek: każde rozgrywki sezonu (liga, puchar, ...) mogą mieć swojego sponsora.
        Schema::create('sponsors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('url')->nullable();
            $table->string('logo_path')->nullable(); // dysk public, katalog sponsors
            $table->timestamps();
        });

        Schema::table('competitions', function (Blueprint $table) {
            $table->foreignId('sponsor_id')->nullable()->after('name')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('competitions', fn (Blueprint $table) => $table->dropConstrainedForeignId('sponsor_id'));
        Schema::dropIfExists('sponsors');
    }
};
