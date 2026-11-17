<?php

namespace App\Http\Controllers\Mirrors;

use App\Domain\Mirrors\Reports;
use App\Http\Controllers\Controller;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** `POST /api/v1/mirrors/report`, a mirror telling the hub what it holds. */
class ReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $result = Reports::handle($request->getContent());

        return Api::json($result['body'], $result['status'], 'no-store');
    }

    public function preflight(): Response
    {
        return Api::preflight();
    }
}
