<?php

namespace App\Http\Controllers\Maps;

use App\Auth\HubSession;
use App\Domain\Maps\Report;
use App\Http\Controllers\Controller;
use App\Supabase\Supabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The map report form's target, the Next app's `reportMap` server action. The
 * page's script posts it and shows the answer in place. Without a script the
 * form posts normally and the answer comes back on the map page.
 */
class MapReportController extends Controller
{
    public function store(Request $request, string $slug): JsonResponse|RedirectResponse
    {
        $state = Report::file(
            HubSession::client() ?? Supabase::anon(),
            HubSession::userId(),
            $request->input('map_id'),
            $request->input('kind'),
            $request->input('reason'),
        );

        if ($request->expectsJson()) {
            return new JsonResponse($state);
        }

        return redirect('/map/'.rawurlencode($slug).'#map-report')->with('mapReport', $state);
    }
}
