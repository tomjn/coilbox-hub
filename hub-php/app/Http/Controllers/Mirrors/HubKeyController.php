<?php

namespace App\Http\Controllers\Mirrors;

use App\Domain\Mirrors\B64;
use App\Domain\Mirrors\HubKeys;
use App\Http\Controllers\Controller;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * `GET /api/v1/mirrors/hub-key`, the key mirrors check the catalogue and
 * upload tokens with. Mirror setup and `bin/check.php` read it.
 */
class HubKeyController extends Controller
{
    public function show(): JsonResponse
    {
        $keys = HubKeys::fromConfig();
        if ($keys === null) {
            return Api::error('The hub has no signing key set.', 503);
        }

        $body = ['public_key' => B64::encode($keys->publicKey())];
        $next = $keys->nextKey();
        if ($next !== null) {
            $body['next'] = $next;
        }

        // No caching, so a rotation shows at once.
        return Api::json($body, 200, 'no-store');
    }

    public function preflight(): Response
    {
        return Api::preflight();
    }
}
