<?php

namespace App\Actions\Seasons;

use App\Enums\SeasonStatus;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Fixture;
use App\Models\Season;
use App\Support\Audit;
use App\Support\PageCache;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cofnięcie zatwierdzenia (zatwierdzony -> szkic). Usuwa wszystkie rozgrywki sezonu (ligi, puchar, podwórkową,
 * Ligę Legend i puchary europejskie, które od automatyzacji też powstają przy zatwierdzeniu) i ich terminarze.
 * Boty dodane przy zatwierdzaniu zostają na liście (admin może ją wyczyścić i zbudować
 * od nowa). Po aktywacji sezonu cofnięcie jest niemożliwe.
 */
class RevertApproval
{
    /** @throws DomainException */
    public function handle(Season $season): void
    {
        DB::transaction(function () use ($season): void {
            // Policzone bloki stron (tabele, mistrzowie) do odświeżenia po zatwierdzeniu zmian.
            PageCache::flush();

            $season = Season::query()->lockForUpdate()->findOrFail($season->id);

            if ($season->status !== SeasonStatus::Approved) {
                throw new DomainException(__('Only an approved season can be reverted to a draft.'));
            }

            // Kasujemy ręcznie od dołu, żeby nie polegać na kaskadach klucza obcego.
            $ids = Competition::where('season_id', $season->id)->pluck('id');

            Fixture::whereIn('competition_id', $ids)->delete();
            CompetitionEntry::whereIn('competition_id', $ids)->delete();
            Competition::whereIn('id', $ids)->delete();

            $season->update(['status' => SeasonStatus::Draft]);

            Audit::log(
                'season.unapproved',
                null,
                ['status' => SeasonStatus::Approved->label()],
                ['status' => SeasonStatus::Draft->label()],
                $season->title,
            );
        });
    }
}
