<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wnioski graczy o zmianę nazwy zespołu (ustawienia konta), zatwierdzane przez admina (dashboard/team-requests).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_name_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Nazwa przed zmianą: zostaje w historii, nawet gdy gracz zmieni ją później.
            $table->string('current_team_name', 40)->nullable();
            $table->string('requested_team_name', 40);
            // pending / approved / rejected.
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_name_change_requests');
    }
};
