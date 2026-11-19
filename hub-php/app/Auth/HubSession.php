<?php

namespace App\Auth;

use App\Supabase\Client;
use App\Supabase\Local\Profiles;
use App\Supabase\Local\SignInStore;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Support\Facades\Cache;

/**
 * The signed in visitor, kept in the Laravel session.
 *
 * Until sign in moves to the hub (#602), Supabase Auth runs it. The Next app
 * kept the Supabase session in `sb-<ref>-auth-token` cookies and refreshed it
 * in `proxy.ts`. Here the tokens live server side in the session, and
 * {@see Client()} refreshes them when they are about to expire, so a page or
 * action only ever asks for a client.
 *
 * Once `users:copy --switch` has run, the hub signs people in itself and the
 * session holds only the account's id and its authenticator level. Every
 * area's rules are the hub's by then, so no Supabase token is needed. A
 * browser still holding Supabase tokens from before the switch keeps its
 * account. After going back to Supabase, a hub session counts as signed out.
 *
 * The `nav` entry is only for drawing the header.
 */
final class HubSession
{
    private const KEY = 'supabase';

    private const HUB = 'hub';

    /** Refresh this many seconds before the access token expires. */
    private const REFRESH_MARGIN = 60;

    /** @param array<string, mixed> $tokens From {@see GoTrue::exchangeCode()}. */
    public static function start(array $tokens): void
    {
        session()->regenerate();
        self::store($tokens);
        // A new account's profile, which Supabase's sign up trigger made
        // while profiles were there.
        Profiles::seed(is_array($tokens['user'] ?? null) ? $tokens['user'] : []);
        self::refreshNav();
    }

    /** Starts a session for an account the hub signed in itself. */
    public static function startHub(string $userId): void
    {
        session()->regenerate();
        session()->forget(self::KEY);
        session()->put(self::HUB, ['user_id' => $userId, 'aal' => 'aal1']);
        request()->attributes->remove('hub.session');
        Profiles::seed(HubAccounts::user($userId) ?? []);
        self::refreshNav();
    }

    /** Whether this browser is signed in by the hub rather than Supabase. */
    public static function onHub(): bool
    {
        return self::hub() !== null;
    }

    public static function userId(): ?string
    {
        $hub = self::hub();
        if ($hub !== null) {
            return $hub['user_id'];
        }
        $tokens = session(self::KEY);

        return is_array($tokens) ? ($tokens['user_id'] ?? null) : null;
    }

    public static function signedIn(): bool
    {
        return self::hub() !== null || self::accessToken() !== null;
    }

    /** The Supabase access token, refreshed when it is close to expiry. Null when signed out or signed in by the hub. */
    public static function accessToken(): ?string
    {
        if (self::hub() !== null) {
            return null;
        }
        $tokens = session(self::KEY);
        if (! is_array($tokens)) {
            return null;
        }
        if ((int) $tokens['expires_at'] - self::REFRESH_MARGIN > time()) {
            return $tokens['access_token'];
        }

        // Refresh tokens are single use with rotation on. Two requests from one
        // browser can race to refresh, so only one does and the other reads
        // the result.
        $lock = Cache::lock('refresh:'.sha1((string) $tokens['refresh_token']), 10);
        try {
            $lock->block(10);
            $fresh = session(self::KEY);
            if (is_array($fresh) && $fresh['refresh_token'] !== $tokens['refresh_token']) {
                return $fresh['access_token'];
            }
            self::store(GoTrue::refresh((string) $tokens['refresh_token']));
            session()->save();

            return session(self::KEY)['access_token'];
        } catch (SupabaseException) {
            self::clear();

            return null;
        } finally {
            $lock->release();
        }
    }

    /** A Supabase client acting as the signed in user, or null when signed out. */
    public static function client(): ?Client
    {
        $hub = self::hub();
        if ($hub !== null) {
            return Supabase::forUser($hub['user_id'], $hub['aal']);
        }
        $token = self::accessToken();

        return $token === null ? null : Supabase::forToken($token, self::userId());
    }

    /** The authenticator level of this session, `aal1` or `aal2`. */
    public static function aal(): string
    {
        $hub = self::hub();
        if ($hub !== null) {
            return $hub['aal'];
        }
        $tokens = session(self::KEY);

        return is_array($tokens) ? (string) ($tokens['aal'] ?? 'aal1') : 'aal1';
    }

    /**
     * Rewrites the header's view of the visitor: their display name and their
     * access to moderation, `writeNav` in the Next app. Call it after anything
     * that changes either.
     */
    public static function refreshNav(): void
    {
        $client = self::client();
        $id = self::userId();
        if ($client === null || $id === null) {
            session()->forget('nav');

            return;
        }
        $name = null;
        try {
            $row = $client->from('profile')->select('display_name')->eq('user_id', $id)->maybeSingle();
            $name = $row['display_name'] ?? null;
        } catch (SupabaseException) {
        }
        $moderation = 'none';
        try {
            $answer = $client->rpc('capability_access', ['capability' => 'can_moderate']);
            $moderation = in_array($answer, ['granted', 'verify', 'enrol', 'none'], true) ? $answer : 'none';
        } catch (SupabaseException) {
        }
        session()->put('nav', ['id' => $id, 'name' => $name, 'moderation' => $moderation]);
    }

    /** Swap in new tokens for the same user, after a second factor upgrades the session. @param array<string, mixed> $tokens */
    public static function replaceTokens(array $tokens): void
    {
        session()->migrate(true);
        self::store($tokens);
        self::refreshNav();
    }

    /** Raises a hub session to `aal2`, after a code from an authenticator app. */
    public static function upgrade(): void
    {
        $hub = self::hub();
        if ($hub === null) {
            return;
        }
        session()->migrate(true);
        session()->put(self::HUB, ['user_id' => $hub['user_id'], 'aal' => 'aal2']);
        request()->attributes->remove('hub.session');
        self::refreshNav();
    }

    public static function signOut(): void
    {
        $tokens = session(self::KEY);
        if (self::hub() === null && is_array($tokens)) {
            GoTrue::signOut((string) $tokens['access_token'], (string) $tokens['refresh_token'], (int) $tokens['expires_at']);
        }
        self::clear();
        session()->invalidate();
        session()->regenerateToken();
    }

    /**
     * The hub session, checked once a request: the account must still exist
     * and not be banned. A browser that signed in through Supabase before the
     * switch becomes a hub session for the same account.
     *
     * @return array{user_id: string, aal: string}|null
     */
    private static function hub(): ?array
    {
        $attributes = request()->attributes;
        if ($attributes->has('hub.session')) {
            return $attributes->get('hub.session');
        }
        $hub = session(self::HUB);
        $tokens = session(self::KEY);
        $onHub = SignInStore::state() === SignInStore::MARIADB;
        if ($onHub && ! is_array($hub) && is_array($tokens) && ($tokens['user_id'] ?? '') !== '') {
            // These tokens came straight from Supabase to this server, so their user id is trusted.
            $hub = ['user_id' => (string) $tokens['user_id'], 'aal' => (string) ($tokens['aal'] ?? 'aal1')];
            session()->forget(self::KEY);
            session()->put(self::HUB, $hub);
        }
        $valid = null;
        if (is_array($hub)) {
            if ($onHub && HubAccounts::active((string) $hub['user_id'])) {
                $valid = ['user_id' => (string) $hub['user_id'], 'aal' => $hub['aal'] === 'aal2' ? 'aal2' : 'aal1'];
            } else {
                session()->forget([self::HUB, 'nav']);
            }
        }
        $attributes->set('hub.session', $valid);

        return $valid;
    }

    private static function clear(): void
    {
        session()->forget([self::KEY, self::HUB, 'nav']);
        request()->attributes->remove('hub.session');
    }

    /** @param array<string, mixed> $tokens */
    private static function store(array $tokens): void
    {
        $claims = GoTrue::claims((string) $tokens['access_token']);
        // Sign in and each refresh bring the account as Supabase holds it.
        if (is_array($tokens['user'] ?? null)) {
            AuthUsers::keep($tokens['user']);
        }
        session()->put(self::KEY, [
            'access_token' => (string) $tokens['access_token'],
            'refresh_token' => (string) $tokens['refresh_token'],
            'expires_at' => (int) $tokens['expires_at'],
            'user_id' => (string) ($tokens['user']['id'] ?? $claims['sub'] ?? ''),
            'aal' => (string) ($claims['aal'] ?? 'aal1'),
        ]);
    }
}
