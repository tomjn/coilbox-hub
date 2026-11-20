<?php

namespace App\Auth;

use App\Models\AuthUser;
use App\Supabase\Local\Values;
use App\Supabase\SupabaseException;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Coilbox's tokens once the hub signs it in (#602, part 3c). Access tokens are
 * Sanctum's, `id|secret`, stored hashed and good for
 * `hub.tokens.access_seconds`. Refresh tokens are the hub's own, stored hashed
 * in `auth_refresh_token`, and change on every use as Supabase's did.
 *
 * A sign in is a session: every token issued from one sign in shares its id,
 * so signing out or a stolen refresh token ends all of them at once.
 *
 * Answers keep the shape of Supabase's `/auth/v1/token`, which the
 * `/api/v1/auth/token` contract with Coilbox (#592) is. Refusals are
 * Supabase's too: `code`, `error_code` and `msg`.
 */
final class HubTokens
{
    /** The start of every hub refresh token, so one is never sent to Supabase. */
    public const REFRESH_PREFIX = 'hubr_';

    /** A Sanctum access token: the row id, a bar and the secret. */
    public static function isAccessToken(string $token): bool
    {
        return (bool) preg_match('/\A[0-9]+\|[A-Za-z0-9]+\z/', $token);
    }

    public static function isRefreshToken(string $token): bool
    {
        return str_starts_with($token, self::REFRESH_PREFIX);
    }

    /**
     * Starts a session for an account and answers its first tokens.
     *
     * @return array<string, mixed>
     */
    public static function start(string $userId): array
    {
        return DB::transaction(function () use ($userId) {
            $session = strtolower((string) Str::uuid());

            return self::answer($userId, $session, self::newRefresh($userId, $session, null));
        });
    }

    /**
     * Swaps a hub refresh token for new tokens. A spent one still works for
     * `hub.tokens.refresh_reuse_seconds` after it was used, or while the
     * token it was swapped for has not been used, which is a client that
     * never got the answer. Used any later, it ends the session.
     *
     * @return array{0: int, 1: array<string, mixed>}|null status and body, or null when the hub never issued this token
     */
    public static function refresh(string $token): ?array
    {
        return DB::transaction(function () use ($token) {
            $row = DB::table('auth_refresh_token')->where('token_hash', self::hash($token))->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }
            if (! HubAccounts::active((string) $row->user_id)) {
                return self::refusal(400, 'user_banned', 'Invalid Refresh Token: User Banned');
            }
            if ($row->revoked_at === null) {
                DB::table('auth_refresh_token')->where('id', $row->id)->update(['revoked_at' => Values::now()]);
            } elseif (! self::inGrace($row)) {
                if (DB::table('auth_refresh_token')->where('parent_id', $row->id)->whereNotNull('revoked_at')->exists()) {
                    self::end((string) $row->session_id);

                    return self::refusal(400, 'refresh_token_already_used', 'Invalid Refresh Token: Already Used');
                }
                // The answer to this token's last use never arrived, so its replacement goes.
                DB::table('auth_refresh_token')->where('parent_id', $row->id)->delete();
            }
            // Access tokens this session no longer needs.
            PersonalAccessToken::query()->where('name', $row->session_id)->where('expires_at', '<', now())->delete();

            $next = self::newRefresh((string) $row->user_id, (string) $row->session_id, (int) $row->id);

            return [200, self::answer((string) $row->user_id, (string) $row->session_id, $next)];
        });
    }

    /**
     * Swaps a refresh token Supabase issued for the hub's, so nobody signed in
     * to Coilbox before the switch has to sign in again. Supabase redeems it,
     * which proves it, and that Supabase session is then ended. The token is
     * kept as the spent first token of the new session, so presenting it again
     * follows the same rules as a spent hub token.
     *
     * @return array{0: int, 1: array<string, mixed>} status and body, Supabase's own when it refused
     *
     * @throws SupabaseException when Supabase Auth could not be reached or failed
     */
    public static function swapSupabase(string $token): array
    {
        $response = GoTrue::token('refresh_token', ['refresh_token' => $token]);
        if ($response->serverError()) {
            throw new SupabaseException('Supabase Auth answered '.$response->status(), $response->status());
        }
        $body = $response->json();
        if (! $response->successful()) {
            return [$response->status(), is_array($body) ? $body : []];
        }
        $user = is_array($body['user'] ?? null) ? $body['user'] : null;
        if ($user === null || ! is_string($user['id'] ?? null)) {
            throw new SupabaseException('Supabase Auth gave no user', 500);
        }
        try {
            GoTrue::revoke((string) ($body['access_token'] ?? ''));
        } catch (SupabaseException $e) {
            // The Supabase session lasts until its refresh token is next used.
            report($e);
        }
        AuthUsers::keep($user);
        $userId = self::accountFor($user);
        if (! HubAccounts::active($userId)) {
            return self::refusal(400, 'user_banned', 'Invalid Refresh Token: User Banned');
        }

        try {
            return DB::transaction(function () use ($token, $userId) {
                $session = strtolower((string) Str::uuid());
                DB::table('auth_refresh_token')->insert([
                    'token_hash' => self::hash($token),
                    'user_id' => $userId,
                    'session_id' => $session,
                    'parent_id' => null,
                    'created_at' => Values::now(),
                    'revoked_at' => Values::now(),
                ]);
                $first = (int) DB::table('auth_refresh_token')->where('token_hash', self::hash($token))->value('id');

                return [200, self::answer($userId, $session, self::newRefresh($userId, $session, $first))];
            });
        } catch (UniqueConstraintViolationException) {
            // The same token swapped twice at once. The other request kept it.
            return self::refresh($token) ?? self::refusal(400, 'refresh_token_not_found', 'Invalid Refresh Token: Refresh Token Not Found');
        }
    }

    /** The account a hub access token belongs to, or null when it is unknown, expired or the account may not sign in. */
    public static function userIdFor(string $token): ?string
    {
        $access = self::access($token);
        if ($access === null) {
            return null;
        }
        $userId = (string) $access->tokenable_id;

        return HubAccounts::active($userId) ? $userId : null;
    }

    /** Ends the session an access token belongs to. False when the token is unknown or expired. */
    public static function signOut(string $token): bool
    {
        $access = self::access($token);
        if ($access === null) {
            return false;
        }
        self::end((string) $access->name);

        return true;
    }

    /**
     * The account in the shape Supabase Auth gave it beside tokens. Coilbox
     * reads `id`, `email` and the names in `user_metadata`.
     *
     * @return array<string, mixed>
     */
    public static function user(string $userId): array
    {
        $row = DB::table('auth_user')->where('id', $userId)->first();
        $time = fn ($value) => $value === null ? null : CarbonImmutable::parse((string) $value, 'UTC')->format('Y-m-d\TH:i:s.u\Z');

        return [
            'id' => (string) $row->id,
            'aud' => 'authenticated',
            'role' => 'authenticated',
            'email' => (string) ($row->email ?? ''),
            'email_confirmed_at' => $time($row->email_confirmed_at),
            'phone' => '',
            'confirmed_at' => $time($row->email_confirmed_at),
            'last_sign_in_at' => $time($row->last_sign_in_at),
            'app_metadata' => json_decode((string) $row->app_metadata, true) ?: new \stdClass,
            'user_metadata' => json_decode((string) $row->user_metadata, true) ?: new \stdClass,
            'identities' => array_map(fn (array $identity) => $identity + ['user_id' => (string) $row->id], HubAccounts::identities((string) $row->id)),
            'created_at' => $time($row->created_at),
            'updated_at' => $time($row->updated_at),
            'is_anonymous' => (bool) $row->is_anonymous,
        ];
    }

    /**
     * A refusal in Supabase Auth's shape.
     *
     * @return array{0: int, 1: array{code: int, error_code: string, msg: string}}
     */
    public static function refusal(int $status, string $code, string $message): array
    {
        return [$status, ['code' => $status, 'error_code' => $code, 'msg' => $message]];
    }

    /**
     * The hub account a Supabase user signs in to. Normally the one with the
     * same id. A Supabase account made by signing in to Coilbox after the
     * browser sign in moved has none of its providers on the hub, because a
     * hub account already held them, so it goes to that account instead.
     *
     * @param  array<string, mixed>  $user
     */
    private static function accountFor(array $user): string
    {
        $userId = strtolower((string) $user['id']);
        if (DB::table('auth_identity')->where('user_id', $userId)->exists()) {
            return $userId;
        }
        foreach (is_array($user['identities'] ?? null) ? $user['identities'] : [] as $identity) {
            if (! is_array($identity) || ! is_string($identity['provider'] ?? null) || ! is_string($identity['id'] ?? null)) {
                continue;
            }
            $holder = DB::table('auth_identity')->where('provider', $identity['provider'])->where('provider_id', $identity['id'])->value('user_id');
            if (is_string($holder)) {
                return $holder;
            }
        }

        return $userId;
    }

    /** Whether a spent refresh token was used recently enough to use again, as two refreshes at once do. */
    private static function inGrace(object $row): bool
    {
        $grace = (int) config('hub.tokens.refresh_reuse_seconds');

        return CarbonImmutable::parse((string) $row->revoked_at, 'UTC')->addSeconds($grace)->isFuture();
    }

    /** Ends a session: its access tokens and refresh tokens all stop working. */
    private static function end(string $session): void
    {
        PersonalAccessToken::query()->where('name', $session)->delete();
        DB::table('auth_refresh_token')->where('session_id', $session)->delete();
    }

    private static function access(string $token): ?PersonalAccessToken
    {
        if (! self::isAccessToken($token)) {
            return null;
        }
        $access = PersonalAccessToken::findToken($token);
        if ($access === null || $access->tokenable_type !== AuthUser::class || ($access->expires_at !== null && $access->expires_at->isPast())) {
            return null;
        }

        return $access;
    }

    /** Stores a new refresh token and answers it. */
    private static function newRefresh(string $userId, string $session, ?int $parent): string
    {
        $token = self::REFRESH_PREFIX.Str::random(48);
        DB::table('auth_refresh_token')->insert([
            'token_hash' => self::hash($token),
            'user_id' => $userId,
            'session_id' => $session,
            'parent_id' => $parent,
            'created_at' => Values::now(),
            'revoked_at' => null,
        ]);

        return $token;
    }

    /** @return array<string, mixed> */
    private static function answer(string $userId, string $session, string $refresh): array
    {
        $seconds = (int) config('hub.tokens.access_seconds');
        $expires = now()->addSeconds($seconds);
        $access = (new AuthUser)->forceFill(['id' => $userId])->createToken($session, ['*'], $expires);

        return [
            'access_token' => $access->plainTextToken,
            'token_type' => 'bearer',
            'expires_in' => $seconds,
            'expires_at' => $expires->getTimestamp(),
            'refresh_token' => $refresh,
            'user' => self::user($userId),
        ];
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
