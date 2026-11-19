<?php

namespace App\Http\Controllers\Assets;

use App\Auth\GoTrue;
use App\Domain\Assets\AuthDiscovery;
use App\Http\Controllers\Controller;
use App\Supabase\SupabaseException;
use App\Support\Api;
use App\Support\Bearer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * `GET /api/v1/auth`, `app/api/v1/auth/route.ts` in the Next app. Coilbox has
 * one configurable address, the hub, and discovers the Supabase project and
 * the vendored file digests from here.
 */
class AuthApiController extends Controller
{
    /** Three base64url parts, the shape of every token Supabase issues. */
    private const JWT = '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D';

    /**
     * A day, not the API's usual minute: the values change close to never and
     * a client asks on every sign in. A 503 rather than a body with a missing
     * field when Supabase is not configured.
     */
    public function show(): JsonResponse
    {
        $body = AuthDiscovery::body();
        if ($body === null) {
            return Api::error('This deployment has not configured Supabase sign-in.', 503);
        }

        return Api::json($body, 200, 'public, max-age=86400, s-maxage=86400');
    }

    /**
     * `POST /api/v1/auth/logout`. Ends the session the bearer token belongs
     * to, so a copied refresh token stops working. Signing out only on the
     * computer left the refresh token valid.
     */
    public function logout(Request $request): JsonResponse|Response
    {
        $token = Bearer::token($request);
        if ($token === null || ! preg_match(self::JWT, $token)) {
            return Api::error('Send an access token as "Authorization: Bearer <token>".', 401);
        }
        try {
            $ended = GoTrue::revoke($token);
        } catch (SupabaseException $e) {
            Log::error('POST /api/v1/auth/logout: Supabase Auth failed', ['error' => $e->getMessage()]);

            return Api::error('Could not reach the sign in service. Try again.', 502);
        }
        if (! $ended) {
            return Api::error('That access token has expired. Refresh it and send the new one.', 401);
        }

        return new Response('', 204, Api::CORS_HEADERS);
    }
}
