<?php

namespace App\Http\Controllers\Mirrors;

use App\Domain\Mirrors\Uploads;
use App\Http\Controllers\Controller;
use App\Support\Api;
use App\Support\Bearer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** `POST /api/v1/uploads` and `GET /api/v1/uploads/{id}`, for signed-in Coilbox users. */
class UploadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $auth = Bearer::authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        // The declared length first, so a body far past the cap is refused before it is read.
        $declared = $request->header('Content-Length');
        $raw = is_numeric($declared) && (float) $declared > Uploads::MAX_BODY_BYTES ? null : $request->getContent();
        if ($raw === null || strlen($raw) > Uploads::MAX_BODY_BYTES) {
            return Api::error('A request body may be at most '.Uploads::MAX_BODY_BYTES.' bytes.', 413);
        }

        $result = Uploads::start($auth, $raw);

        return Api::json($result['body'], $result['status'], 'no-store');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $auth = Bearer::authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $result = Uploads::status($auth, $id);

        return Api::json($result['body'], $result['status'], 'no-store');
    }

    public function preflight(): Response
    {
        return Api::preflight();
    }
}
