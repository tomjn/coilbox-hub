<?php

namespace App\Http\Controllers\Assets;

use App\Auth\AuthUsers;
use App\Auth\CoilboxSignIn;
use App\Auth\GoTrue;
use App\Auth\HubTokens;
use App\Domain\Assets\AuthDiscovery;
use App\Http\Controllers\Controller;
use App\Supabase\Local\TokenStore;
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
     * address. Supabase checks `redirect_to` against its allowed list. Once
     * `tokens:store mariadb` has run, the hub runs the sign in itself, see
     * {@see CoilboxSignIn}.
     */
    public function authorize(Request $request): RedirectResponse|JsonResponse
    {
        if (TokenStore::fresh() === TokenStore::MARIADB) {
            $provider = $request->query('provider');
            $redirectTo = $request->query('redirect_to');
            $challenge = $request->query('code_challenge');
            $method = $request->query('code_challenge_method');
            $refusal = CoilboxSignIn::refuse($provider, $redirectTo, $challenge, $method);
            if ($refusal !== null) {
                return self::answer($refusal);
            }

            return CoilboxSignIn::start($provider, $redirectTo, $challenge, $method);
        }
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
     * Codes and refresh tokens the hub issued are the hub's to answer, see
     * {@see HubTokens}. Once switched, a Supabase refresh token is swapped for
     * the hub's.
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
        // The hub's own codes and refresh tokens, whichever way the switch is
        // set, so going back to Supabase signs nobody out.
        if ($grant === 'pkce' && CoilboxSignIn::ownsCode($fields['auth_code'])) {
            return self::answer(CoilboxSignIn::exchange($fields['auth_code'], $fields['code_verifier']));
        }
        if ($grant === 'refresh_token') {
            $refreshed = HubTokens::refresh($fields['refresh_token']);
            if ($refreshed !== null) {
                return self::answer($refreshed);
            }
            if (HubTokens::isRefreshToken($fields['refresh_token'])) {
                return self::answer(HubTokens::refusal(400, 'refresh_token_not_found', 'Invalid Refresh Token: Refresh Token Not Found'));
            }
        }
        if (! Supabase::configured()) {
            return Api::error('This deployment has not configured Supabase sign-in.', 503);
        }
        if ($grant === 'refresh_token' && TokenStore::fresh() === TokenStore::MARIADB) {
            try {
                return self::answer(HubTokens::swapSupabase($fields['refresh_token']));
            } catch (SupabaseException $e) {
                Log::error('POST /api/v1/auth/token: Supabase Auth failed', ['error' => $e->getMessage()]);

                return Api::error('Could not reach the sign in service. Try again.', 502);
            }
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
     * computer left the refresh token valid. A hub token's session ends on
     * the hub, a Supabase token's at Supabase.
     */
    public function logout(Request $request): JsonResponse|Response
    {
        $token = Bearer::token($request);
        if ($token !== null && HubTokens::isAccessToken($token)) {
            if (! HubTokens::signOut($token)) {
                return Api::error('That access token has expired. Refresh it and send the new one.', 401);
            }

            return new Response('', 204, Api::CORS_HEADERS);
        }
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

    /**
     * A token answer or refusal the hub made, sent as Supabase sent its own.
     *
     * @param  array{0: int, 1: array<string, mixed>}  $answer
     */
    private static function answer(array $answer): JsonResponse
    {
        // Tokens must never sit in a shared cache.
        return response()->json($answer[1], $answer[0], Api::CORS_HEADERS + ['Cache-Control' => 'no-store']);
    }
}
