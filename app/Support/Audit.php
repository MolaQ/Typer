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
        ];
    }

    public static function label(string $event): string
    {
        return self::events()[$event] ?? $event;
    }

    public static function color(string $event): string
    {
        return match (true) {
            str_ends_with($event, '.approved') => 'green',
            str_ends_with($event, '.rejected') => 'red',
            str_ends_with($event, '.requested') => 'amber',
            default => 'zinc',
        };
    }
}