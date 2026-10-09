<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Informacje systemowe (publiczny dziennik zdarzeń gry): treść jako klucz tłumaczenia + parametry,
        // link jako nazwa trasy + parametry (adres liczy się przy wyświetlaniu, więc działa też w podkatalogu XAMPP).
        Schema::create('system_events', function (Blueprint $table) {
            $table->id();
            $table->string('category', 30)->index();
            $table->string('message');
            $table->json('params')->nullable();
            $table->string('route', 100)->nullable();
            $table->json('route_params')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // gracz, którego dotyczy wpis
            $table->timestamp('created_at')->nullable()->index();
        });

        // Newsy pisane w panelu (uprawnienie news-create).
        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // autor
            $table->string('title', 150);
            $table->text('body');
            $table->timestamp('published_at')->nullable()->index(); // null = szkic
            $table->timestamps();
        });

        // Oceny newsów: kciuk w górę (1) albo w dół (-1), jedna ocena gracza na news.
        Schema::create('news_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_id')->constrained('news')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('value');
            $table->timestamps();
            $table->unique(['news_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_votes');
        Schema::dropIfExists('news');
        Schema::dropIfExists('system_events');
    }
};
