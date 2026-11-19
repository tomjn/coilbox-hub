<?php

namespace App\Http\Controllers\Assets;

use App\Auth\AuthUsers;
use App\Auth\GoTrue;
use App\Domain\Assets\AuthDiscovery;
use App\Http\Controllers\Controller;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use App\Support\Api;
use App\Support\Bearer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

    /** The grants the token endpoint passes on, and the fields each needs. */
    private const GRANTS = [
        'pkce' => ['auth_code', 'code_verifier'],
        'refresh_token' => ['refresh_token'],
    ];

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
     * `GET /api/v1/auth/authorize`. Sends the browser to Supabase's authorize
     * endpoint with the query Coilbox sent, so Coilbox needs only the hub's
     * address. Supabase checks `redirect_to` against its allowed list.
     */
    public function authorize(Request $request): RedirectResponse|JsonResponse
    {
        if (! Supabase::configured()) {
            return Api::error('This deployment has not configured Supabase sign-in.', 503);
        }
        $query = (string) $request->server('QUERY_STRING');

        return redirect()->away(Supabase::url().'/auth/v1/authorize'.($query !== '' ? '?'.$query : ''), 302);
    }

    /**
     * `POST /api/v1/auth/token`. Swaps a PKCE code for a session, or a
     * refresh token for a new session, through Supabase. The answer is
     * Supabase's, status and body, so Coilbox reads it as it reads Supabase.
     * `grant_type` may come in the query, as Supabase takes it, or the body.
     */
    public function token(Request $request): JsonResponse
    {
        $grant = $request->query('grant_type', $request->input('grant_type'));
        $required = self::GRANTS[is_string($grant) ? $grant : ''] ?? null;
        if ($required === null) {
            return Api::error('grant_type must be "pkce" or "refresh_token".', 400);
        }
        $fields = [];
        foreach ($required as $name) {
            $value = $request->input($name);
            if (! is_string($value) || $value === '') {
                return Api::error("\"$name\" must be a non-empty string.", 400);
            }
            $fields[$name] = $value;
        }
        if (! Supabase::configured()) {
            return Api::error('This deployment has not configured Supabase sign-in.', 503);
        }
        try {
            $response = GoTrue::token($grant, $fields);
        } catch (SupabaseException $e) {
            Log::error('POST /api/v1/auth/token: Supabase Auth failed', ['error' => $e->getMessage()]);

            return Api::error('Could not reach the sign in service. Try again.', 502);
        }
        if ($response->serverError()) {
            Log::error('POST /api/v1/auth/token: Supabase Auth answered '.$response->status());

            return Api::error('Could not reach the sign in service. Try again.', 502);
        }
        // Coilbox signing in, or refreshing, brings the account as Supabase holds it.
        $body = $response->json();
        if ($response->successful() && is_array($body['user'] ?? null)) {
            AuthUsers::keep($body['user']);
        }

        // Tokens must never sit in a shared cache.
        return response()->json($body ?? [], $response->status(), Api::CORS_HEADERS + ['Cache-Control' => 'no-store']);
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
