<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Wpłaty (etap 15): premium i wsparcie (cegiełki). Kwoty w groszach. Online przez Przelewy24
        // albo wpisane ręcznie przez admina. Suma opłaconych wpłat z 12 miesięcy decyduje o Złotej Lidze.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16); // premium | support
            $table->string('plan', 16)->nullable(); // klucz z cennika (Premium::PLANS)
            $table->unsignedInteger('amount'); // grosze
            $table->string('currency', 3)->default('PLN');
            $table->string('status', 16)->default('pending'); // pending | paid | failed
            $table->string('source', 16)->default('p24'); // p24 | manual
            $table->string('session_id', 100)->nullable()->unique(); // identyfikator transakcji po naszej stronie
            $table->unsignedBigInteger('p24_order_id')->nullable();
            $table->unsignedSmallInteger('premium_days')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'paid_at']);
            $table->index(['user_id', 'status']);
        });

        // Premium: rola z datą wygaśnięcia (po niej rola znika) i domyślny typ (regulamin: 0:0, dopóki gracz go nie ustawi).
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('premium_until')->nullable()->after('team_abbr');
            $table->unsignedTinyInteger('default_tip_lech')->nullable()->after('premium_until');
            $table->unsignedTinyInteger('default_tip_opponent')->nullable()->after('default_tip_lech');
        });

        // Typ wpisany automatycznie z domyślnego typu premium (gracz nie wytypował do pierwszego gwizdka).
        Schema::table('tips', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('opponent_goals');
        });
    }

    public function down(): void
    {
        Schema::table('tips', fn (Blueprint $table) => $table->dropColumn('is_default'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['premium_until', 'default_tip_lech', 'default_tip_opponent']));
        Schema::dropIfExists('payments');
    }
};
