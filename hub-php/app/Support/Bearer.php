<?php

namespace App\Support;

use App\Auth\AuthUsers;
use App\Auth\HubAccounts;
use App\Auth\HubTokens;
use App\Supabase\Client;
use App\Supabase\Local\Profiles;
use App\Supabase\Supabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The desktop app's sign in on `/api/v1`, `lib/supabase/bearer.ts` in the Next
 * app. Coilbox sends its access token as `Authorization: Bearer <token>`.
 * A hub token (`id|secret`) is checked on the hub, a Supabase one (a JWT) by
 * Supabase Auth, and nothing else in the request is trusted about who is
 * calling.
 *
 * {@see authenticate()} returns either the caller or the 401 response to send
 * back, so a controller returns early when it gets a JsonResponse.
 */
final class Bearer
{
    /** @param array<string, mixed> $user */
    private function __construct(
        public readonly array $user,
        public readonly string $userId,
        public readonly Client $client,
    ) {}

    public static function token(Request $request): ?string
    {
        $header = trim((string) $request->header('Authorization', ''));
        if (! preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return null;
        }
        $token = trim($m[1]);

        return $token === '' ? null : $token;
    }

    /** The caller, or the 401 the Next app answered with. */
    public static function authenticate(Request $request): self|JsonResponse
    {
        $token = self::token($request);
        if ($token === null) {
            return Api::error('Send an access token as "Authorization: Bearer <token>".', 401);
        }
        // The hub's own tokens (#602, part 3c). Supabase's still work until
        // they expire, and from copies of Coilbox that sign in with Supabase.
        if (HubTokens::isAccessToken($token)) {
            $userId = HubTokens::userIdFor($token);
            $user = $userId === null ? null : HubAccounts::user($userId);
            if ($user === null) {
                return Api::error('That access token is not valid. Sign in again and use a fresh one.', 401);
            }
            Profiles::seed($user);

            // Coilbox does not ask for an authenticator code, so aal1, as its Supabase tokens were.
            return new self($user, $userId, Supabase::forUser($userId, 'aal1'));
        }
        $user = Supabase::userForToken($token);
        if ($user === null) {
            return Api::error('That access token is not valid. Sign in again and use a fresh one.', 401);
        }

        // An account that signed up in Coilbox and has not been here yet.
        Profiles::seed($user);
        AuthUsers::keep($user);

        return new self($user, (string) $user['id'], Supabase::forToken($token, (string) $user['id']));
    }
}
