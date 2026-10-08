<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Propozycje pytań bonusowych od graczy (formularz w stopce strony). Po akceptacji admina pytanie trafia do banku.
        Schema::create('question_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('text', 255);
            $table->string('side', 20); // offensive / defensive (propozycja gracza, admin może zmienić)
            $table->string('status', 20)->default('pending')->index(); // pending / accepted / rejected
            $table->string('reject_reason', 255)->nullable();
            $table->foreignId('question_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_proposals');
    }
};
