<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biuro skarg i wniosków (15.10.2026): odwołania od bana i statusu Inactive, wnioski o zmianę nazwy bota (premium,
 * najpierw głosowanie graczy) i inne sprawy do admina. Zastępuje dawne propozycje nazw botów (bot_name_proposals).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('bot_name_proposals');

        Schema::create('office_requests', function (Blueprint $table) {
            $table->id();
            // Autor sprawy.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // ban_appeal | inactive_appeal | bot_rename | other (OfficeRequest::TYPES).
            $table->string('type', 20)->index();
            // Wniosek o zmianę nazwy: bot i proponowana nazwa (stara nazwa do historii).
            $table->foreignId('bot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('bot_name', 40)->nullable();
            $table->string('old_bot_name', 40)->nullable();
            // Uzasadnienie od autora.
            $table->text('body');
            // voting (głosowanie graczy) | pending (czeka na admina) | accepted | rejected | vote_failed (przepadł w głosowaniu).
            $table->string('status', 20)->index();
            // Liczniki głosów (kopia z office_request_votes).
            $table->unsignedInteger('votes_up')->default(0);
            $table->unsignedInteger('votes_down')->default(0);
            // Odpowiedź admina widoczna dla autora.
            $table->string('admin_reply', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('office_request_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 1 = za (kciuk w górę), -1 = przeciw.
            $table->tinyInteger('vote');
            $table->timestamps();
            $table->unique(['office_request_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_request_votes');
        Schema::dropIfExists('office_requests');
    }
};
