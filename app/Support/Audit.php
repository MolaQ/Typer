<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;

class Audit
{
    /**
     * Zapisuje wpis w dzienniku zmian.
     *
     * @param  array<string, mixed>  $old  wartości przed zmianą
     * @param  array<string, mixed>  $new  wartości po zmianie
     */
    public static function log(
        string $event,
        ?User $target = null,
        array $old = [],
        array $new = [],
        ?string $description = null,
    ): AuditLog {
        $actor = auth()->user();

        return AuditLog::create([
            'event' => $event,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'target_id' => $target?->id,
            'target_name' => $target?->name,
            'description' => $description,
            'properties' => ['old' => $old, 'new' => $new],
            'ip_address' => request()->ip(),
        ]);
    }

    /** Dostępne zdarzenia i ich etykiety (do filtrów i widoku). */
    public static function events(): array
    {
        return [
            'user.registered' => __('Account created'),
            'team_name.requested' => __('Team change requested'),
            'team_name.cancelled' => __('Team change request cancelled'),
            'team_name.approved' => __('Team change approved'),
            'team_name.rejected' => __('Team change rejected'),
            'team_name.changed' => __('Team details changed'),
            'roles.updated' => __('Roles updated'),

            // Sezony
            'season.created' => __('Season created'),
            'season.updated' => __('Season updated'),
            'season.activated' => __('Season activated'),
            'season.finished' => __('Season finished'),
            'season.deleted' => __('Season deleted'),

            // Kolejki sezonu (mecze Lecha)
            'matchday.created' => __('Matchdays created'),
            'matchday.updated' => __('Matchday updated'),

            // Lista przedsezonowa
            'season_list.built' => __('Team list built'),
            'season_list.players_added' => __('Players added to the list'),
            'season_list.moved' => __('Team moved to another league'),
            'season_list.reordered' => __('Team order changed'),
            'season_list.reset' => __('Team list cleared'),
            'season_list.bots_added' => __('Bots added to the list'),

            // Boty
            'bot.renamed' => __('Bot renamed'),
        ];
    }

    public static function label(string $event): string
    {
        return self::events()[$event] ?? $event;
    }

    /** Kolor plakietki wynika z końcówki nazwy zdarzenia. */
    public static function color(string $event): string
    {
        return match (true) {
            str_ends_with($event, '.approved'),
            str_ends_with($event, '.activated'),
            str_ends_with($event, '.created'),
            str_ends_with($event, '.built'),
            str_ends_with($event, '.players_added'),
            str_ends_with($event, '.bots_added') => 'green',

            str_ends_with($event, '.rejected'),
            str_ends_with($event, '.deleted'),
            str_ends_with($event, '.reset') => 'red',

            str_ends_with($event, '.requested') => 'amber',

            default => 'zinc',
        };
    }
}