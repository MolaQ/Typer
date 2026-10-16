<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konta graczy i adminów oraz tabele logowania Laravela (reset hasła, sesje).
 * Gracz = konto z dowolną rolą i bez ról blokujących (App\Support\Players), role w tabelach spatie/laravel-permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Nazwa zespołu gracza: jedna na cały serwis (unikalna także wśród botów, App\Rules\UniqueTeamValue).
            // Zmiana nazwy idzie przez wniosek do admina (team_name_change_requests).
            $table->string('team_name', 40)->nullable()->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            // Potwierdzenie adresu e-mail 6-cyfrowym kodem zamiast linku. Kod widzi też admin
            // w panelu Użytkownicy, żeby mógł pomóc graczowi, do którego mail nie dotarł.
            $table->string('verification_code', 6)->nullable();
            $table->timestamp('verification_code_expires_at')->nullable();
            $table->string('password');
            // Premium (regulamin, Premium): rola z datą wygaśnięcia; po niej rola znika (premium:expire co godzinę).
            $table->timestamp('premium_until')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
