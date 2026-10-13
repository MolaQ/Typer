<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wpłaty (etap 15): premium i wsparcie. Kwoty w groszach. Online przez Przelewy24 albo wpisane ręcznie przez admina.
 * Suma opłaconych wpłat z 12 miesięcy układa ranking wspierających (App\Support\Supporters).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // premium | support.
            $table->string('kind', 16);
            // Klucz z cennika (Premium::PLANS), tylko premium.
            $table->string('plan', 16)->nullable();
            // Kwota w groszach.
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('PLN');
            // pending | paid | failed.
            $table->string('status', 16)->default('pending');
            // p24 | manual.
            $table->string('source', 16)->default('p24');
            // Identyfikator transakcji po naszej stronie i numer zamówienia w Przelewy24.
            $table->string('session_id', 100)->nullable()->unique();
            $table->unsignedBigInteger('p24_order_id')->nullable();
            // O ile dni wpłata przedłuża premium.
            $table->unsignedSmallInteger('premium_days')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->string('note')->nullable();
            // Admin, który wpisał wpłatę ręcznie.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'paid_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
