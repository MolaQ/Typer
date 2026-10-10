<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Boty to stała pula 512 zespołów bez właścicieli, wspólna dla wszystkich sezonów.
        // Dzięki temu bot ma tę samą nazwę w każdym sezonie, a admin może ją zmienić.
        Schema::create('bots', function (Blueprint $table) {
            $table->id();

            // Numer slotu 1..512. Wiąże bota z domyślną nazwą z App\Support\BotNames.
            $table->unsignedSmallInteger('sort_order')->unique();

            // Nazwa wyświetlana w tabelach (edytowalna w panelu admina).
            $table->string('name', 40)->unique();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bots');
    }
};
