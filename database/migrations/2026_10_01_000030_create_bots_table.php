<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boty: stała pula 512 zespołów bez właścicieli, wspólna dla wszystkich sezonów (BotsSeeder).
 * Bot ma tę samą nazwę w każdym sezonie, a admin może ją zmienić (dashboard/bots, propozycje nazw graczy).
 * Propozycje nazw botów od graczy (dashboard/bot-name-proposals).
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

        // Propozycje nazw botów (formularz w stopce i na stronie głównej). Admin akceptuje, nadając nazwę botowi, albo odrzuca.
        Schema::create('bot_name_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('text', 40)->unique();
            // pending | accepted | rejected (BotNameProposal::PENDING...).
            $table->string('status', 20)->default('pending')->index();
            $table->string('reject_reason', 255)->nullable();
            // Bot, który dostał nazwę po akceptacji.
            $table->foreignId('bot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_name_proposals');
        Schema::dropIfExists('bots');
    }
};
