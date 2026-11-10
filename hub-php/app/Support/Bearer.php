<?php

namespace App\Support;

use App\Supabase\Client;
use App\Supabase\Supabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The desktop app's sign in on `/api/v1`, `lib/supabase/bearer.ts` in the Next
 * app. Coilbox signs in with Supabase itself and sends the access token as
 * `Authorization: Bearer <token>`. Supabase Auth verifies it, and nothing else
 * in the request is trusted about who is calling.
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
        $user = Supabase::userForToken($token);
        if ($user === null) {
            return Api::error('That access token is not valid. Sign in again and use a fresh one.', 401);
        }

        return new self($user, (string) $user['id'], Supabase::forToken($token));
    }
}
