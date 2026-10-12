<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Krótka nazwa i skrót zespołu nie są nigdzie używane: zostaje tylko pełna nazwa.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['team_short_name']);
            $table->dropUnique(['team_abbr']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['team_short_name', 'team_abbr']);
        });

        Schema::table('team_name_change_requests', function (Blueprint $table) {
            $table->dropColumn(['current_team_short_name', 'current_team_abbr', 'requested_team_short_name', 'requested_team_abbr']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('team_short_name', 20)->nullable()->unique()->after('team_name');
            $table->string('team_abbr', 6)->nullable()->unique()->after('team_short_name');
        });

        Schema::table('team_name_change_requests', function (Blueprint $table) {
            $table->string('current_team_short_name', 20)->nullable();
            $table->string('current_team_abbr', 6)->nullable();
            $table->string('requested_team_short_name', 20)->nullable();
            $table->string('requested_team_abbr', 6)->nullable();
        });
    }
};
