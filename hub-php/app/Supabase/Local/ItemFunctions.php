<?php

namespace App\Supabase\Local;

use App\Auth\Factors;
use App\Auth\GoTrue;
use App\Supabase\Client;
use App\Supabase\SupabaseException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The account and gallery functions the hub calls, done in PHP on the local
 * tables (#601), as production's schema defines them on 19 November 2026.
 * Each keeps its grants and its answers.
 *
 * Accounts stay in Supabase Auth. `capability_access` reads the session's
 * authenticator level from its access token and the account's second factors
 * from `/auth/v1/user`, where Postgres read `auth.jwt()` and
 * `auth.mfa_factors`. `moderator_accounts` lists the accounts with the
 * service role's `/auth/v1/admin/users`, where Postgres read `auth.users`.
 */
final class ItemFunctions
{
    /** Function => roles granted execute. */
    private const GRANTS = [
        'is_moderator' => ['anon', 'authenticated'],
        'has_capability' => ['anon', 'authenticated'],
        'capability_access' => ['anon', 'authenticated'],
        'moderator_accounts' => ['authenticated'],
        'set_item_featured' => ['authenticated'],
        'current_author_name' => ['authenticated'],
    ];

    private const WRITES = ['set_item_featured'];

    /** `moderator_accounts` answers at most this many. */
    public const ACCOUNTS_PAGE = 50;

    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    public static function handles(string $function): bool
    {
        return isset(self::GRANTS[$function]);
    }

    public static function writes(string $function): bool
    {
        return in_array($function, self::WRITES, true);
    }

    /** @param array<string, mixed> $args */
    public static function call(Client $client, string $function, array $args): mixed
    {
        $caller = new Caller($client);
        if (! in_array($caller->role(), self::GRANTS[$function], true)) {
            throw new LocalException('permission denied for function '.$function, 401, '42501');
        }
        $text = fn (string $name) => isset($args[$name]) ? (string) $args[$name] : null;

        try {
            return match ($function) {
                'is_moderator' => self::capabilityAccess($caller, 'can_moderate') === 'granted',
                'has_capability' => self::capabilityAccess($caller, (string) $text('capability')) === 'granted',
                'capability_access' => self::capabilityAccess($caller, (string) $text('capability')),
                'moderator_accounts' => self::accounts($caller, $text('p_search'), isset($args['p_offset']) ? (int) $args['p_offset'] : null),
                'set_item_featured' => self::feature($caller, (string) $text('item_id'), (bool) ($args['featured'] ?? false)),
                'current_author_name' => self::currentAuthorName($caller),
            };
        } catch (QueryException $e) {
            throw new LocalException($e->getMessage(), 503, null);
        }
    }

    /**
     * `public.capability_access(capability)`: `none` without the capability,
     * `granted` in a session that entered a second factor or for an account
     * with none, otherwise `verify`. `enrol` would follow from
     * `capabilities_require_second_factor()`, which is false.
     */
    public static function capabilityAccess(Caller $caller, string $capability): string
    {
        $userId = $caller->userId();
        if ($userId === null || ! self::holds($userId, $capability)) {
            return 'none';
        }
        if ($caller->client->aal() === 'aal2') {
            return 'granted';
        }
        // Once sign in runs on the hub, its authenticators count. A Coilbox
        // token still comes from Supabase, so Supabase's count for it too.
        if (SignInStore::state() === SignInStore::MARIADB) {
            if (Factors::hasVerified($userId)) {
                return 'verify';
            }
            if ($caller->client->hubSession()) {
                return 'granted';
            }
        }

        return self::hasVerifiedFactor((string) $caller->client->accessToken()) ? 'verify' : 'granted';
    }

    /** Whether an account holds a capability, whatever its session. */
    public static function holds(string $userId, string $capability): bool
    {
        return DB::table('user_capability')->where('user_id', $userId)->where('capability', $capability)->exists();
    }

    /** `public.current_author_name()`: the caller's display name, or `Unknown`. */
    public static function currentAuthorName(Caller $caller): string
    {
        $userId = $caller->userId();
        $name = $userId === null ? null : DB::table('profile')->where('user_id', $userId)->value('display_name');

        return is_string($name) ? $name : 'Unknown';
    }

    /** Asked once per token in a request. */
    private static function hasVerifiedFactor(string $token): bool
    {
        return once(function () use ($token) {
            try {
                $user = GoTrue::user($token);
            } catch (SupabaseException $e) {
                throw new LocalException($e->getMessage(), 503, null);
            }
            if ($user === null) {
                throw new LocalException('JWT expired', 401, 'PGRST301');
            }
            foreach ($user['factors'] ?? [] as $factor) {
                if (($factor['status'] ?? null) === 'verified') {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * `public.set_item_featured(item_id, featured)`: a moderator puts a live
     * item at the top of the gallery, or takes any item off it. True when a
     * row changed.
     */
    private static function feature(Caller $caller, string $itemId, bool $featured): bool
    {
        if (! $caller->isModerator()) {
            throw new LocalException('featuring a gallery item needs can_moderate', 403, '42501');
        }
        if (preg_match(self::UUID, $itemId) !== 1) {
            throw new LocalException('invalid input syntax for type uuid: "'.$itemId.'"', 400, '22P02');
        }
        $definer = $caller->definer();

        return DB::transaction(function () use ($definer, $itemId, $featured) {
            $query = DB::table('item')->where('id', strtolower($itemId));
            if ($featured) {
                $query->whereNull('deleted_at');
            }
            $targets = array_map(fn ($row) => (array) $row, $query->lockForUpdate()->get()->all());
            $now = Values::now();
            $values = $featured ? ['featured_at' => $now, 'featured_by' => $definer->userId()] : ['featured_at' => null, 'featured_by' => null];

            return Writes::update($definer, 'item', $targets, $values, $now) !== [];
        });
    }

    /**
     * `public.moderator_accounts(p_search, p_offset)`: fifty accounts, newest
     * first, each with its display name and capabilities. A search matches an
     * exact id or part of a display name.
     *
     * @return list<array{id: string, display_name: string, created_at: string, capabilities: list<string>}>
     */
    private static function accounts(Caller $caller, ?string $search, ?int $offset): array
    {
        if (! $caller->isModerator()) {
            throw new LocalException('moderator_accounts is for moderators', 403, '42501');
        }
        try {
            // Accounts made on the hub since sign in moved are only in MariaDB.
            $users = SignInStore::state() === SignInStore::MARIADB
                ? DB::table('auth_user')->get(['id', 'created_at'])->map(fn ($row) => ['id' => $row->id, 'created_at' => (string) $row->created_at])->all()
                : GoTrue::adminUsers();
        } catch (SupabaseException $e) {
            throw new LocalException($e->getMessage(), 503, null);
        }
        $names = DB::table('profile')->pluck('display_name', 'user_id')->all();
        $capabilities = [];
        foreach (DB::table('user_capability')->orderBy('capability')->get() as $row) {
            $capabilities[$row->user_id][] = (string) $row->capability;
        }
        $pattern = $search === null ? null : self::ilike('%'.$search.'%');

        $rows = [];
        foreach ($users as $user) {
            $id = strtolower((string) ($user['id'] ?? ''));
            $name = $names[$id] ?? null;
            if ($search !== null && $id !== $search && ($name === null || preg_match($pattern, $name) !== 1)) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'display_name' => $name ?? '',
                'created_at' => Values::timestamp(Values::stored((string) ($user['created_at'] ?? 'now'))),
                'capabilities' => $capabilities[$id] ?? [],
            ];
        }
        usort($rows, fn (array $a, array $b) => strcmp(Values::stored($b['created_at']), Values::stored($a['created_at'])) ?: strcmp($a['id'], $b['id']));

        return array_slice($rows, max($offset ?? 0, 0), self::ACCOUNTS_PAGE);
    }

    /** A Postgres `ilike` pattern as a regular expression: `%` any run, `_` one character, `\` escapes. */
    private static function ilike(string $pattern): string
    {
        $out = '';
        $length = mb_strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($pattern, $i, 1);
            if ($char === '\\' && $i + 1 < $length) {
                $out .= preg_quote(mb_substr($pattern, ++$i, 1), '/');
            } elseif ($char === '%') {
                $out .= '.*';
            } elseif ($char === '_') {
                $out .= '.';
            } else {
                $out .= preg_quote($char, '/');
            }
        }

        return '/\A'.$out.'\z/isu';
    }
}
