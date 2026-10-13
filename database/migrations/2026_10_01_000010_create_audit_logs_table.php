<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dziennik zmian w panelu admina (App\Support\Audit): kto, komu i co zmienił.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Rodzaj zdarzenia, np. "season.approved" (etykiety w dzienniku zmian).
            $table->string('event', 60)->index();
            // Kto wykonał akcję. Nazwa zostaje w logu, nawet gdy konto zostanie usunięte.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            // Kogo dotyczy zmiana.
            $table->foreignId('target_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_name')->nullable();
            $table->string('description')->nullable();
            // JSON z wartościami przed i po zmianie ("old" / "new").
            $table->longText('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
