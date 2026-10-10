<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dziennik zapisów typów: każdy zapis (nowy typ albo zmiana) dostaje kolejny numer z autoinkrementacji.
        // Numer jest ściśle rosnący i nie powtarza się nawet przy zapisach w tej samej chwili, więc zastępuje
        // porównywanie czasu z mikrosekundami w remisach (puchary, Liga Legend): wcześniejszy numer = lepszy.
        Schema::create('tip_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matchday_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at', 6)->nullable();

            $table->index(['matchday_id', 'user_id']);
        });

        // Numer ostatniego zapisu typu (id z tip_saves). Domyślny typ premium go nie ma (liczy się jak bot).
        Schema::table('tips', function (Blueprint $table) {
            $table->unsignedBigInteger('save_order')->nullable()->after('saved_at')->index();
        });

        // Kopia numeru w dorobku kolejki (null = bot, brak typu albo domyślny typ premium).
        Schema::table('team_scores', function (Blueprint $table) {
            $table->unsignedBigInteger('tip_order')->nullable()->after('tipped_at');
        });

        // Istniejące typy dostają numery w kolejności czasu zapisu.
        $tips = DB::table('tips')->where('is_default', false)->orderBy('saved_at')->orderBy('id')->get(['id', 'matchday_id', 'user_id', 'saved_at']);

        foreach ($tips as $tip) {
            $order = DB::table('tip_saves')->insertGetId([
                'matchday_id' => $tip->matchday_id,
                'user_id' => $tip->user_id,
                'created_at' => $tip->saved_at,
            ]);

            DB::table('tips')->where('id', $tip->id)->update(['save_order' => $order]);

            $teamIds = DB::table('season_teams')
                ->join('matchdays', 'matchdays.season_id', '=', 'season_teams.season_id')
                ->where('matchdays.id', $tip->matchday_id)
                ->where('season_teams.user_id', $tip->user_id)
                ->pluck('season_teams.id');

            DB::table('team_scores')->where('matchday_id', $tip->matchday_id)->whereIn('season_team_id', $teamIds)
                ->update(['tip_order' => $order]);
        }
    }

    public function down(): void
    {
        Schema::table('team_scores', function (Blueprint $table) {
            $table->dropColumn('tip_order');
        });

        Schema::table('tips', function (Blueprint $table) {
            $table->dropIndex(['save_order']);
            $table->dropColumn('save_order');
        });

        Schema::dropIfExists('tip_saves');
    }
};
