<?php

namespace App\Auth;

use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Supabase Auth over HTTP, the calls supabase-js made for the Next app's web
 * sign in: start an OAuth sign in with PKCE, exchange the code, refresh a
 * session and sign out.
 *
 * Sign in uses PKCE. The verifier never leaves this server's session, and the
 * code Supabase sends back is useless without it.
 */
final class GoTrue
{
    /** The OAuth providers switched on in supabase/config.toml, in the order offered. */
    public const PROVIDERS = ['discord' => 'Discord', 'github' => 'GitHub', 'twitch' => 'Twitch'];

    /** Takes request input as it comes, which can be an array from a crafted query. */
    public static function provider(mixed $value): ?string
    {
        return is_string($value) && isset(self::PROVIDERS[$value]) ? $value : null;
    }

    /** @return array{verifier: string, challenge: string} */
    public static function pkcePair(): array
    {
        $verifier = self::base64url(random_bytes(48));

        return ['verifier' => $verifier, 'challenge' => self::base64url(hash('sha256', $verifier, true))];
    }

    /** Where to send the browser to sign in with a provider. */
    public static function authorizeUrl(string $provider, string $redirectTo, string $challenge): string
    {
        return Supabase::url().'/auth/v1/authorize?'.http_build_query([
            'provider' => $provider,
            'redirect_to' => $redirectTo,
            'code_challenge' => $challenge,
            'code_challenge_method' => 's256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Where to send a signed in browser to link another provider to the same
     * account, supabase-js's `linkIdentity`. Needs the user's access token, so
     * Supabase answers with the URL rather than redirecting.
     */
    public static function linkIdentityUrl(string $accessToken, string $provider, string $redirectTo, string $challenge): string
    {
        $response = self::request($accessToken)->get(Supabase::url().'/auth/v1/user/identities/authorize', [
            'provider' => $provider,
            'redirect_to' => $redirectTo,
            'code_challenge' => $challenge,
            'code_challenge_method' => 's256',
            'skip_http_redirect' => 'true',
        ]);
        $body = self::json($response, 'Could not start linking');

        return (string) ($body['url'] ?? throw new SupabaseException('Supabase gave no link URL', 500));
    }

    /** @return array<string, mixed> The session: access_token, refresh_token, expires_at, user. */
    public static function exchangeCode(string $code, string $verifier): array
    {
        $response = self::reach(fn () => self::request()->post(Supabase::url().'/auth/v1/token?grant_type=pkce', [
            'auth_code' => $code,
            'code_verifier' => $verifier,
        ]));

        return self::session(self::json($response, 'Could not finish signing in'));
    }

    /** @return array<string, mixed> */
    public static function refresh(string $refreshToken): array
    {
        $response = self::reach(fn () => self::request()->post(Supabase::url().'/auth/v1/token?grant_type=refresh_token', [
            'refresh_token' => $refreshToken,
        ]));

        return self::session(self::json($response, 'Could not refresh the session'));
    }

    /**
     * The signed in user as Supabase Auth holds them, including `identities`
     * and `factors`. Null when the token is no longer valid.
     *
     * @return array<string, mixed>|null
     */
    public static function user(string $accessToken): ?array
    {
        $response = self::reach(fn () => self::request($accessToken)->get(Supabase::url().'/auth/v1/user'));
        if ($response->status() === 401 || $response->status() === 403) {
            return null;
        }

        return self::json($response, 'Could not read your account');
    }

    /** @return array<string, mixed> The new factor: id, totp.qr_code, totp.secret. */
    public static function enrolTotp(string $accessToken, string $friendlyName): array
    {
        $response = self::request($accessToken)->post(Supabase::url().'/auth/v1/factors', [
            'factor_type' => 'totp',
            'friendly_name' => $friendlyName,
        ]);

        return self::json($response, 'Could not start setting up an authenticator');
    }

    public static function unenrol(string $accessToken, string $factorId): void
    {
        $response = self::request($accessToken)->delete(Supabase::url().'/auth/v1/factors/'.rawurlencode($factorId));
        self::json($response, 'Could not remove that authenticator');
    }

    /**
     * Challenge a factor and verify a code against it in one step,
     * supabase-js's `challengeAndVerify`. A verified code upgrades the
     * session to `aal2`, so Supabase answers with new tokens.
     *
     * @return array<string, mixed>
     */
    public static function challengeAndVerify(string $accessToken, string $factorId, string $code): array
    {
        $base = Supabase::url().'/auth/v1/factors/'.rawurlencode($factorId);
        $challenge = self::json(self::request($accessToken)->post($base.'/challenge'), 'Could not challenge that authenticator');
        $response = self::request($accessToken)->post($base.'/verify', [
            'challenge_id' => (string) ($challenge['id'] ?? ''),
            'code' => $code,
        ]);

        return self::session(self::json($response, 'That code did not match'));
    }

    public static function unlinkIdentity(string $accessToken, string $identityId): void
    {
        $response = self::request($accessToken)->delete(Supabase::url().'/auth/v1/user/identities/'.rawurlencode($identityId));
        self::json($response, 'Could not remove that way of signing in');
    }

    /** Deletes a user with the service role key. Their rows go by the database's foreign keys. */
    public static function adminDeleteUser(string $userId): void
    {
        $key = (string) config('hub.supabase.service_role_key');
        $response = Http::withHeaders(['apikey' => $key, 'Authorization' => 'Bearer '.$key])
            ->acceptJson()->timeout(10)
            ->delete(Supabase::url().'/auth/v1/admin/users/'.rawurlencode($userId));
        self::json($response, 'Could not delete the account');
    }

    /** The `user_metadata` Supabase holds for a user, with the service role key. Null when there is no such user. */
    public static function adminUserMetadata(string $userId): ?array
    {
        $key = (string) config('hub.supabase.service_role_key');
        $response = Http::withHeaders(['apikey' => $key, 'Authorization' => 'Bearer '.$key])
            ->acceptJson()->timeout(10)
            ->get(Supabase::url().'/auth/v1/admin/users/'.rawurlencode($userId));
        if ($response->status() === 404) {
            return null;
        }
        $user = self::json($response, 'Could not read the account');
        $metadata = $user['user_metadata'] ?? [];

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * One account as Supabase Auth holds it, with its `identities`, read with
     * the service role key. Null when there is no such user.
     *
     * @return array<string, mixed>|null
     */
    public static function adminUser(string $userId): ?array
    {
        $key = (string) config('hub.supabase.service_role_key');
        $response = self::reach(fn () => Http::withHeaders(['apikey' => $key, 'Authorization' => 'Bearer '.$key])
            ->acceptJson()->timeout(10)
            ->get(Supabase::url().'/auth/v1/admin/users/'.rawurlencode($userId)));
        if ($response->status() === 404) {
            return null;
        }

        return self::json($response, 'Could not read the account');
    }

    /**
     * Every account Supabase Auth holds, with the service role key, read a
     * page at a time. The list leaves out each account's `identities`.
     *
     * @return list<array<string, mixed>>
     */
    public static function adminUsers(): array
    {
        $key = (string) config('hub.supabase.service_role_key');
        $perPage = 1000;
        $users = [];
        for ($page = 1; ; $page++) {
            $response = self::reach(fn () => Http::withHeaders(['apikey' => $key, 'Authorization' => 'Bearer '.$key])
                ->acceptJson()->timeout(10)
                ->get(Supabase::url().'/auth/v1/admin/users', ['page' => $page, 'per_page' => $perPage]));
            $found = self::json($response, 'Could not list the accounts')['users'] ?? [];
            if (! is_array($found)) {
                throw new SupabaseException('Supabase Auth listed no accounts', 500);
            }
            // Until an empty page, in case Supabase gives fewer than asked.
            if ($found === []) {
                return $users;
            }
            array_push($users, ...array_values($found));
        }
    }

    /**
     * Ends a browser's session at Supabase. Supabase refuses an expired access
     * token, so one near its end is refreshed first. Failure is ignored: the
     * local session is cleared either way.
     */
    public static function signOut(string $accessToken, string $refreshToken, int $expiresAt): void
    {
        try {
            if ($expiresAt <= time() + 30) {
                $accessToken = (string) self::refresh($refreshToken)['access_token'];
            }
            self::revoke($accessToken);
        } catch (SupabaseException) {
        }
    }

    /**
     * Supabase's own token endpoint, answer and all, for Coilbox's sign in
     * through the hub. Callers pass the answer on, so an error keeps the body
     * Supabase gave.
     *
     * @param  'pkce'|'refresh_token'  $grantType
     * @param  array<string, string>  $fields
     *
     * @throws SupabaseException when Supabase Auth could not be reached
     */
    public static function token(string $grantType, array $fields): Response
    {
        return self::reach(fn () => self::request()->post(Supabase::url().'/auth/v1/token?grant_type='.$grantType, $fields));
    }

    /**
     * Ends the session an access token belongs to, so its refresh tokens stop
     * working. True when the session ended or was already gone, false when
     * Supabase refused the token, which is how it answers an expired one.
     *
     * @throws SupabaseException when Supabase Auth could not be reached or failed
     */
    public static function revoke(string $accessToken): bool
    {
        $response = self::reach(fn () => self::request($accessToken)->post(Supabase::url().'/auth/v1/logout?scope=local'));
        if ($response->successful()) {
            return true;
        }
        $body = $response->json();
        if ($response->status() === 403 && is_array($body) && ($body['error_code'] ?? null) === 'session_not_found') {
            return true;
        }
        if ($response->status() === 401 || $response->status() === 403) {
            return false;
        }
        throw SupabaseException::fromResponse($response->status(), is_array($body) ? $body : null, 'Could not sign out');
    }

    /**
     * Reads the claims of an access token Supabase just issued to this
     * server. Not a signature check: only call it on a token that came
     * straight from {@see exchangeCode()} or {@see refresh()}.
     *
     * @return array<string, mixed>
     */
    public static function claims(string $accessToken): array
    {
        $parts = explode('.', $accessToken);
        $payload = isset($parts[1]) ? json_decode(self::base64urlDecode($parts[1]), true) : null;

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function session(array $body): array
    {
        if (! isset($body['access_token'], $body['refresh_token'])) {
            throw new SupabaseException('Supabase returned no session', 500);
        }
        $body['expires_at'] = (int) ($body['expires_at'] ?? (time() + (int) ($body['expires_in'] ?? 3600)));

        return $body;
    }

    /**
     * Runs a request, turning "could not reach Supabase" into the same
     * exception as an error answer, so callers have one failure to handle.
     *
     * @param  \Closure(): Response  $send
     */
    private static function reach(\Closure $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException $e) {
            throw new SupabaseException('Could not reach Supabase Auth: '.$e->getMessage(), 0, 'CONNECTION');
        }
    }

    /** @return array<string, mixed> */
    private static function json(Response $response, string $fallback): array
    {
        $body = $response->json();
        if (! $response->successful()) {
            throw SupabaseException::fromResponse($response->status(), is_array($body) ? $body : null, $fallback);
        }

        return is_array($body) ? $body : [];
    }

    private static function request(?string $accessToken = null): PendingRequest
    {
        $headers = ['apikey' => (string) config('hub.supabase.publishable_key')];
        if ($accessToken !== null) {
            $headers['Authorization'] = 'Bearer '.$accessToken;
        }

        return Http::withHeaders($headers)->acceptJson()->asJson()->timeout(10);
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4));
    }
}
