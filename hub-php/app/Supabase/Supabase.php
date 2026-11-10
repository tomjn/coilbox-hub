<?php

namespace App\Supabase;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Builds Supabase clients from `config/hub.php`. See {@see Client} for which
 * one to use where.
 */
class Supabase
{
    public static function configured(): bool
    {
        return self::url() !== '' && config('hub.supabase.publishable_key') !== '';
    }

    public static function anon(): Client
    {
        return new Client(self::url(), (string) config('hub.supabase.publishable_key'), null, self::timeout());
    }

    public static function forToken(string $accessToken): Client
    {
        return new Client(self::url(), (string) config('hub.supabase.publishable_key'), $accessToken, self::timeout());
    }

    public static function admin(): Client
    {
        $key = (string) config('hub.supabase.service_role_key');
        if ($key === '') {
            throw new SupabaseException('SUPABASE_SERVICE_ROLE_KEY is not set', 500, 'CONFIG');
        }

        return new Client(self::url(), $key, null, self::timeout());
    }

    /**
     * Who an access token belongs to, asked of Supabase Auth, the same check
     * the Next app's `authenticateBearer` makes with `auth.getUser(token)`.
     * Returns the user object, or null when the token is not valid.
     *
     * @return array<string, mixed>|null
     */
    public static function userForToken(string $accessToken): ?array
    {
        try {
            $response = Http::withHeaders([
                'apikey' => (string) config('hub.supabase.publishable_key'),
                'Authorization' => 'Bearer '.$accessToken,
            ])->acceptJson()->timeout(self::timeout())->get(self::url().'/auth/v1/user');
        } catch (ConnectionException $e) {
            throw new SupabaseException('Could not reach Supabase Auth: '.$e->getMessage(), 0, 'CONNECTION');
        }
        if ($response->status() === 401 || $response->status() === 403) {
            return null;
        }
        if (! $response->successful()) {
            throw SupabaseException::fromResponse($response->status(), $response->json(), 'Supabase Auth request failed');
        }
        $user = $response->json();

        return is_array($user) && isset($user['id']) ? $user : null;
    }

    public static function url(): string
    {
        return (string) config('hub.supabase.url');
    }

    private static function timeout(): int
    {
        return (int) config('hub.supabase.timeout', 10);
    }
}
