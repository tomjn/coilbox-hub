<?php

namespace App\Http\Controllers\Assets;

use App\Domain\Assets\AuthDiscovery;
use App\Http\Controllers\Controller;
use App\Support\Api;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/auth`, `app/api/v1/auth/route.ts` in the Next app. Coilbox has
 * one configurable address, the hub, and discovers the Supabase project and
 * the vendored file digests from here.
 */
class AuthApiController extends Controller
{
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
}
