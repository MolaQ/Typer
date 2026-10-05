<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 60)->index();

            // Kto wykonał akcję (nazwa zostaje w logu, nawet gdy konto zostanie usunięte).
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();

            // Kogo dotyczy zmiana.
            $table->foreignId('target_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_name')->nullable();

            $table->string('description')->nullable();
            $table->longText('properties')->nullable(); // JSON: old / new
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};