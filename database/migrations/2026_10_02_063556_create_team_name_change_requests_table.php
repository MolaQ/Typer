<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
Schema::create('team_name_change_requests', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();

    // stan przed zmianą (zostaje w historii, nawet gdy użytkownik zmieni dane później)
    $table->string('current_team_name', 40)->nullable();
    $table->string('current_team_short_name', 20)->nullable();
    $table->string('current_team_abbr', 6)->nullable();

    // żądany stan
    $table->string('requested_team_name', 40);
    $table->string('requested_team_short_name', 20);
    $table->string('requested_team_abbr', 6);

    $table->string('status', 20)->default('pending')->index();
    $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('reviewed_at')->nullable();
    $table->string('reject_reason')->nullable();
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_name_change_requests');
    }
};
