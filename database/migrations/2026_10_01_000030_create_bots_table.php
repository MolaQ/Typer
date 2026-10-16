<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boty: stała pula 512 zespołów bez właścicieli, wspólna dla wszystkich sezonów (BotsSeeder).
 * Bot ma tę samą nazwę w każdym sezonie, a admin może ją zmienić (dashboard/bots, wnioski z Biura skarg i wniosków).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bots', function (Blueprint $table) {
            $table->id();
            // Numer miejsca w puli 1-512, wiąże bota z domyślną nazwą z App\Support\BotNames.
            $table->unsignedSmallInteger('sort_order')->unique();
            // Nazwa wyświetlana w tabelach (kursywą), unikalna także wśród zespołów graczy.
            $table->string('name', 40)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bots');
    }
};
