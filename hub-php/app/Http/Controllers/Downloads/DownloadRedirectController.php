<?php

namespace App\Http\Controllers\Downloads;

use App\Domain\Downloads\Downloads;
use App\Domain\Games\Cached;
use App\Domain\Games\Download;
use App\Domain\Maps\MapPage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The page's download buttons point here so a click can be counted. The index
 * is into the same list the page draws, so a button's number always matches.
 */
class DownloadRedirectController extends Controller
{
    public function map(Request $request, string $slug, int $n): RedirectResponse
    {
        $page = MapPage::cached($slug);
        abort_if($page === null, 404);

        return $this->send('map', (string) $page['mapName'], Download::readDownloads($page['facts']['downloads'] ?? []), $n, $request);
    }

    public function game(Request $request, string $shortname, int $n): RedirectResponse
    {
        $page = Cached::gamePage($shortname);
        abort_if($page === null, 404);

        return $this->send('game', (string) $page['shortname'], $page['downloads'], $n, $request);
    }

    /** A failed count never keeps someone from the file. */
    private function send(string $kind, string $target, array $downloads, int $n, Request $request): RedirectResponse
    {
        $href = isset($downloads[$n]) ? Download::downloadHref($downloads[$n]) : null;
        abort_if($href === null, 404);

        Downloads::record($kind, $target, $request);

        return redirect()->away($href);
    }
}
