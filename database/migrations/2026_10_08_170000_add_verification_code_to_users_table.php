<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Potwierdzenie adresu e-mail 6-cyfrowym kodem (zamiast linku). Kod widzi też admin
        // na stronie Użytkownicy, żeby mógł pomóc graczowi, do którego mail nie dotarł.
        Schema::table('users', function (Blueprint $table) {
            $table->string('verification_code', 6)->nullable()->after('email_verified_at');
            $table->timestamp('verification_code_expires_at')->nullable()->after('verification_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['verification_code', 'verification_code_expires_at']);
        });
    }
};
