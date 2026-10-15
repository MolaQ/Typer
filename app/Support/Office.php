<?php

namespace App\Support;

use App\Enums\RoleName;
use App\Models\Bot;
use App\Models\OfficeRequest;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Biuro skarg i wniosków (15.10.2026, strona /office, panel dashboard/office):
 *  - odwołanie od bana (tylko z rolą Banned) i od statusu Inactive (tylko z rolą Inactive): od razu do admina,
 *    akceptacja zdejmuje rolę (konto bez ról dostaje User i wraca na listę sezonu, Roster::syncUser),
 *  - wniosek o zmianę nazwy wybranego bota (tylko premium): najpierw głosują gracze (kciuk w górę / w dół, każdy raz,
 *    głos można zmienić, autor nie głosuje). Po VOTES_NEEDED głosach wniosek z co najmniej APPROVAL % głosów za trafia
 *    do admina, inaczej przepada. Akceptacja nadaje botowi nową nazwę (BotNames::rules),
 *  - inna skarga lub wniosek: od razu do admina.
 * Admin może rozpatrzyć każdą otwartą sprawę (także w trakcie głosowania) i dopisać odpowiedź dla autora.
 */
final class Office
{
    /** Tyle głosów zamyka głosowanie. */
    public const VOTES_NEEDED = 50;

    /** Minimalny udział głosów za (procent), żeby wniosek trafił do admina. */
    public const APPROVAL = 50;

    /** Najwięcej otwartych wniosków o nazwę bota jednego gracza. */
    public const MAX_OPEN_RENAMES = 3;

    public const BODY_MAX = 2000;

    /**
     * Rodzaje spraw, które gracz może teraz złożyć.
     *
     * @return array<string, string> typ => etykieta
     */
    public static function availableTypes(User $user): array
    {
        $types = [];

        if ($user->hasRole(RoleName::Banned->value)) {
            $types[OfficeRequest::BAN_APPEAL] = __('Ban appeal');
        }
        if ($user->hasRole(RoleName::Inactive->value)) {
            $types[OfficeRequest::INACTIVE_APPEAL] = __('Inactive status appeal');
        }
        if (Premium::isActive($user)) {
            $types[OfficeRequest::BOT_RENAME] = __('Bot rename request');
        }
        $types[OfficeRequest::OTHER] = __('Other complaint or request');

        return $types;
    }

    /** Czy gracz może głosować nad wnioskami (zalogowany, z potwierdzonym e-mailem, bez bana). */
    public static function canVote(?User $user): bool
    {
        return $user !== null && $user->hasVerifiedEmail() && ! $user->hasRole(RoleName::Banned->value);
    }

    /**
     * Złożenie sprawy. Zwraca nowy wniosek albo rzuca DomainException z komunikatem dla gracza.
     *
     * @param  array{type: string, body: string, bot_id?: int|null, bot_name?: string|null}  $data
     */
    public static function submit(User $user, array $data): OfficeRequest
    {
        $type = $data['type'];

        if (! array_key_exists($type, self::availableTypes($user))) {
            throw new DomainException(__('You cannot send this kind of request.'));
        }

        $open = OfficeRequest::open()->where('user_id', $user->id);

        if ($type === OfficeRequest::BOT_RENAME) {
            if ((clone $open)->where('type', $type)->count() >= self::MAX_OPEN_RENAMES) {
                throw new DomainException(__('You already have :count open bot rename requests.', ['count' => self::MAX_OPEN_RENAMES]));
            }
            if (OfficeRequest::open()->where('type', $type)->where('bot_id', $data['bot_id'] ?? 0)->exists()) {
                throw new DomainException(__('There is already an open request for this bot.'));
            }
        } elseif ((clone $open)->where('type', $type)->exists()) {
            throw new DomainException(__('You already have an open request of this kind.'));
        }

        $bot = $type === OfficeRequest::BOT_RENAME ? Bot::find($data['bot_id'] ?? 0) : null;

        return OfficeRequest::create([
            'user_id' => $user->id,
            'type' => $type,
            'bot_id' => $bot?->id,
            'bot_name' => $bot ? $data['bot_name'] : null,
            'old_bot_name' => $bot?->name,
            'body' => $data['body'],
            'status' => $type === OfficeRequest::BOT_RENAME ? OfficeRequest::VOTING : OfficeRequest::PENDING,
        ]);
    }

    /**
     * Głos gracza (1 = za, -1 = przeciw; ten sam głos drugi raz go cofa). Po VOTES_NEEDED głosach
     * wniosek idzie do admina albo przepada.
     */
    public static function vote(OfficeRequest $request, User $user, int $vote): void
    {
        DB::transaction(function () use ($request, $user, $vote): void {
            $request = OfficeRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($request->status !== OfficeRequest::VOTING || $request->user_id === $user->id || ! self::canVote($user)) {
                return;
            }

            $vote = $vote > 0 ? 1 : -1;
            $current = DB::table('office_request_votes')->where('office_request_id', $request->id)->where('user_id', $user->id)->value('vote');

            if ((int) $current === $vote) {
                DB::table('office_request_votes')->where('office_request_id', $request->id)->where('user_id', $user->id)->delete();
            } else {
                DB::table('office_request_votes')->updateOrInsert(
                    ['office_request_id' => $request->id, 'user_id' => $user->id],
                    ['vote' => $vote, 'updated_at' => now(), 'created_at' => now()],
                );
            }

            $counts = DB::table('office_request_votes')->where('office_request_id', $request->id)
                ->selectRaw('sum(case when vote > 0 then 1 else 0 end) as up, sum(case when vote < 0 then 1 else 0 end) as down')
                ->first();

            $request->votes_up = (int) ($counts->up ?? 0);
            $request->votes_down = (int) ($counts->down ?? 0);

            if ($request->votes() >= self::VOTES_NEEDED) {
                $request->status = $request->approval() >= self::APPROVAL ? OfficeRequest::PENDING : OfficeRequest::VOTE_FAILED;
            }

            $request->save();
        });
    }

    /** Głosy gracza na podanych wnioskach: id wniosku => 1 / -1. @param array<int, int> $ids @return array<int, int> */
    public static function votesOf(?User $user, array $ids): array
    {
        if (! $user || $ids === []) {
            return [];
        }

        return DB::table('office_request_votes')->where('user_id', $user->id)->whereIn('office_request_id', $ids)
            ->pluck('vote', 'office_request_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Decyzja admina. Akceptacja odwołania zdejmuje rolę, akceptacja wniosku o nazwę nadaje ją botowi
     * ($botName pozwala ją poprawić). Rzuca DomainException, gdy nie da się jej wykonać.
     */
    public static function decide(OfficeRequest $request, bool $accept, ?string $reply, User $admin, ?string $botName = null): void
    {
        DB::transaction(function () use ($request, $accept, $reply, $admin, $botName): void {
            $request = OfficeRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! in_array($request->status, OfficeRequest::OPEN, true)) {
                throw new DomainException(__('This request has already been handled.'));
            }

            $before = $request->status;

            if ($accept) {
                match ($request->type) {
                    OfficeRequest::BAN_APPEAL => self::liftRole($request->user, RoleName::Banned->value, __('Appeal accepted')),
                    OfficeRequest::INACTIVE_APPEAL => self::liftRole($request->user, RoleName::Inactive->value, __('Appeal accepted')),
                    OfficeRequest::BOT_RENAME => self::renameBot($request, $botName ?: (string) $request->bot_name),
                    default => null,
                };
            }

            $request->update([
                'status' => $accept ? OfficeRequest::ACCEPTED : OfficeRequest::REJECTED,
                'admin_reply' => $reply !== null && trim($reply) !== '' ? trim($reply) : null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            Audit::log('office.'.($accept ? 'accepted' : 'rejected'), $request->user, ['status' => $before], ['status' => $request->status], $request->typeLabel());
        });
    }

    /**
     * Zdjęcie roli blokującej (Banned albo Inactive) z konta: konto bez ról dostaje User, gracz wraca na listę
     * sezonu (Roster::syncUser). Zwraca liczbę miejsc dodanych na listach sezonów.
     */
    public static function liftRole(?User $user, string $role, string $note): int
    {
        if (! $user || ! in_array($role, Players::BLOCKING_ROLES, true) || ! $user->hasRole($role)) {
            return 0;
        }

        $wasPlayer = Players::canPlay($user);

        $user->removeRole($role);
        $user->unsetRelation('roles');
        if (! $user->roles()->exists()) {
            $user->assignRole(RoleName::User->value);
            $user->unsetRelation('roles');
        }

        if (! $wasPlayer && Players::canPlay($user)) {
            SystemFeed::record('players', ':name joined the game', ['name' => $user->team_name ?: $user->name], 'team.show', ['user' => $user->id], $user->id);
        }

        Audit::log('user.role_removed', $user, ['roles' => [$role]], ['roles' => $user->roles->pluck('name')->all()], $note);

        return Roster::syncUser($user)['added'];
    }

    private static function renameBot(OfficeRequest $request, string $name): void
    {
        $bot = $request->bot;
        if (! $bot) {
            throw new DomainException(__('The bot no longer exists.'));
        }

        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        $validator = Validator::make(['name' => $name], ['name' => BotNames::rules($bot->id)], [], ['name' => __('Bot name')]);
        if ($validator->fails()) {
            throw new DomainException($validator->errors()->first('name'));
        }

        $old = $bot->name;
        $bot->update(['name' => $name]);
        $request->bot_name = $name;

        Audit::log('bot.renamed', $request->user, ['name' => $old], ['name' => $name], __('Bot').' #'.$bot->sort_order);
    }
}
