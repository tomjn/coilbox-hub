<?php

namespace App\Http\Controllers\Replays;

use App\Domain\Replays\ReplayStatus;
use App\Domain\Replays\ReplayUploads;
use App\Http\Controllers\Controller;
use App\Support\Api;
use App\Support\Bearer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Sending a replay and asking what became of it, for signed-in Coilbox users. */
class ReplayUploadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $auth = Bearer::authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $result = ReplayUploads::start($auth, $request->getContent());

        return Api::json($result['body'], $result['status'], 'no-store');
    }

    public function status(Request $request, string $gameId): JsonResponse
    {
        $auth = Bearer::authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $result = ReplayStatus::of($auth->userId, $gameId);

        return Api::json($result['body'], $result['status'], 'no-store');
    }

    public function preflight(): Response
    {
        return Api::preflight();
    }
}
