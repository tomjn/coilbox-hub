<?php

namespace App\Http\Controllers\Gallery;

use App\Domain\Profile\Author;
use App\Http\Controllers\Controller;
use App\Support\Api;
use App\Support\Bearer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed in account's display name (#439), for the desktop app to show in
 * place of a name built from provider metadata. Ported from
 * `app/api/v1/profile/route.ts`.
 */
class ProfileApiController extends Controller
{
    /**
     * The answer belongs to whoever sent the token, so it is `private,
     * no-store`. A shared cache holding it would hand one person's name to
     * the next caller.
     */
    public function show(Request $request): JsonResponse
    {
        $auth = Bearer::authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        return Api::json(['display_name' => Author::displayName($auth->client, $auth->userId)], 200, 'private, no-store');
    }
}
