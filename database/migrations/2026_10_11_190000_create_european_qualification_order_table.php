<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolejność kryteriów kwalifikacji do pucharów europejskich (panel „Kwalifikacje europejskie”).
     * Pusta tabela = kolejność domyślna z App\Support\EuropeanQualification::DEFAULT_ORDER.
     */
    public function up(): void
    {
        Schema::create('european_qualification_order', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->unsignedSmallInteger('position');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('european_qualification_order');
    }
};
