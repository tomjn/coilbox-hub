<?php

namespace App\Http\Controllers\Gallery;

use App\Domain\Points\CommunityPoints;
use App\Http\Controllers\Controller;
use App\Support\Api;
use App\Support\Bearer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed in account's community points, for the desktop app to show. */
class PointsApiController extends Controller
{
    /** Private and `no-store`, because the total belongs to whoever sent the token. */
    public function show(Request $request): JsonResponse
    {
        $auth = Bearer::authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        return Api::json(['points' => CommunityPoints::total($auth->userId)], 200, 'private, no-store');
    }
}
