<?php

namespace App\Http\Controllers\Replays;

use App\Domain\Replays\ReplayAnalyses;
use App\Http\Controllers\Controller;
use App\Support\Api;
use App\Support\Bearer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** `POST /api/v1/replays/{gameId}/analyses`, for signed-in Coilbox users. */
class ReplayAnalysisController extends Controller
{
    public function store(Request $request, string $gameId): JsonResponse
    {
        $auth = Bearer::authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $result = ReplayAnalyses::submit($auth, $gameId, $request->getContent());

        return Api::json($result['body'], $result['status'], 'no-store');
    }

    public function preflight(): Response
    {
        return Api::preflight();
    }
}
