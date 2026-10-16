<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indeksy do zestawień wielu sezonów (szybkość, 16.10.2026): mecze po zespole (gospodarz, gość) i wpisy rozgrywek po miejscu na liście.
 * MySQL zakłada je zwykle sam przy kluczach obcych, więc dodajemy tylko brakujące (np. SQLite).
 */
return new class extends Migration
{
    /** @var array<string, list<string>> tabela => kolumny z osobnym indeksem */
    private const INDEXES = [
        'fixtures' => ['home_entry_id', 'away_entry_id'],
        'competition_entries' => ['season_team_id'],
        'designated_players' => ['user_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasIndex($table, [$column])) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($column, $table.'_'.$column.'_lookup'));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasIndex($table, $table.'_'.$column.'_lookup')) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($table.'_'.$column.'_lookup'));
                }
            }
        }
    }
};
