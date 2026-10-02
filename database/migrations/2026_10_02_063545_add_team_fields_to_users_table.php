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
    Schema::table('users', function (Blueprint $table) {
        $table->string('team_name', 40)->nullable()->unique()->after('name');
        $table->string('team_short_name', 20)->nullable()->after('team_name');
        $table->string('team_abbr', 6)->nullable()->unique()->after('team_short_name');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn(['team_name', 'team_short_name', 'team_abbr']);
    });
}
};
