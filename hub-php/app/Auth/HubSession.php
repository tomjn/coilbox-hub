<?php

namespace App\Auth;

use App\Supabase\Client;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Support\Facades\Cache;

/**
 * The signed in visitor, kept in the Laravel session.
 *
 * The Next app kept the Supabase session in `sb-<ref>-auth-token` cookies and
 * refreshed it in `proxy.ts`. Here the tokens live server side in the session,
 * and {@see client()} refreshes them when they are about to expire, so a page
 * or action only ever asks for a client.
 *
 * Every write still goes through a client carrying the user's own token, so
 * row level security decides what they may do, exactly as before. The `nav`
 * entry is only for drawing the header.
 */
final class HubSession
{
    private const KEY = 'supabase';

    /** Refresh this many seconds before the access token expires. */
    private const REFRESH_MARGIN = 60;

    /** @param array<string, mixed> $tokens From {@see GoTrue::exchangeCode()}. */
    public static function start(array $tokens): void
    {
        session()->regenerate();
        self::store($tokens);
        self::refreshNav();
    }

    public static function userId(): ?string
    {
        $tokens = session(self::KEY);

        return is_array($tokens) ? ($tokens['user_id'] ?? null) : null;
    }

    public static function signedIn(): bool
    {
        return self::accessToken() !== null;
    }

    /** The access token, refreshed when it is close to expiry. Null when signed out. */
    public static function accessToken(): ?string
    {
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
        $token = self::accessToken();

        return $token === null ? null : Supabase::forToken($token);
    }

    /** The authenticator level of this session, `aal1` or `aal2`. */
    public static function aal(): string
    {
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

    public static function signOut(): void
    {
        $tokens = session(self::KEY);
        if (is_array($tokens)) {
            GoTrue::signOut((string) $tokens['access_token']);
        }
        self::clear();
        session()->invalidate();
        session()->regenerateToken();
    }

    private static function clear(): void
    {
        session()->forget([self::KEY, 'nav']);
    }

    /** @param array<string, mixed> $tokens */
    private static function store(array $tokens): void
    {
        $claims = GoTrue::claims((string) $tokens['access_token']);
        session()->put(self::KEY, [
            'access_token' => (string) $tokens['access_token'],
            'refresh_token' => (string) $tokens['refresh_token'],
            'expires_at' => (int) $tokens['expires_at'],
            'user_id' => (string) ($tokens['user']['id'] ?? $claims['sub'] ?? ''),
            'aal' => (string) ($claims['aal'] ?? 'aal1'),
        ]);
    }
}
