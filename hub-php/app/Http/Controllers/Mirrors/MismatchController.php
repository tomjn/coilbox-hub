<?php

namespace App\Http\Controllers\Mirrors;

use App\Domain\Assets\UploadIp;
use App\Domain\Mirrors\Mismatch;
use App\Http\Controllers\Controller;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** `POST /api/v1/archives/mismatch`, an anonymous report from Coilbox. */
class MismatchController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $declared = $request->header('Content-Length');
        $raw = is_numeric($declared) && (float) $declared > Mismatch::MAX_BODY_BYTES ? null : $request->getContent();
        if ($raw === null || strlen($raw) > Mismatch::MAX_BODY_BYTES) {
            return Api::error('A request body may be at most '.Mismatch::MAX_BODY_BYTES.' bytes.', 413);
        }

        $result = Mismatch::handle($raw, UploadIp::clientIp($request));

        return Api::json($result['body'], $result['status'], 'no-store');
    }

    public function preflight(): Response
    {
        return Api::preflight();
    }
}
