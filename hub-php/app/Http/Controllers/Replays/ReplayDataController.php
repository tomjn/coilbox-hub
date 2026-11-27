<?php

namespace App\Http\Controllers\Replays;

use App\Domain\Replays\ReplayData;
use App\Http\Controllers\Controller;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** `/replays/data`, the weekly files of shared replay data (#672), and their downloads. */
class ReplayDataController extends Controller
{
    public function index(): View
    {
        return view('replays.data', [
            'weeks' => ReplayData::weeks(),
            'columns' => ReplayData::columns(),
        ]);
    }

    /** One file. `latest` as the week always gives the newest set, for scripts that fetch it. */
    public function download(string $week, string $file): BinaryFileResponse
    {
        $name = substr($file, 0, -4);
        $path = ReplayData::path($week, $name);
        abort_if($path === null, 404);
        $dated = $week === 'latest' ? basename(dirname($path)) : $week;

        return response()->download($path, "coilbox-replays-{$dated}-{$file}", [
            'Content-Type' => 'text/csv; charset=utf-8',
            // A new set is written once a week. Running the command again the same day replaces that day's set.
            'Cache-Control' => $week === 'latest' ? 'public, max-age=3600' : 'public, max-age=86400',
        ]);
    }
}
