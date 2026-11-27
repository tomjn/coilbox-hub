<?php

namespace App\Http\Controllers\Replays;

use App\Domain\Gallery\SearchParams;
use App\Domain\Replays\HeatPicture;
use App\Domain\Replays\ReplayList;
use App\Domain\Replays\ReplayPage;
use App\Http\Controllers\Controller;
use App\Support\HubCache;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** `/replays`, the list of shared match replays, and each replay's page. */
class ReplaysController extends Controller
{
    public function index(Request $request): View
    {
        $filters = ReplayList::parseFilters(SearchParams::fromRequest($request)->toRecord());
        $page = ReplayList::page($filters);

        return view('replays.index', [
            'filters' => $filters,
            'replays' => $page['replays'],
            'count' => $page['count'],
            'maps' => ReplayList::maps(array_column($page['replays'], 'map')),
            'options' => ReplayList::options(),
            'lastPage' => max(1, (int) ceil($page['count'] / ReplayList::PAGE_SIZE)),
        ]);
    }

    /**
     * One replay's page. The panel's tab, the opening's length and the event
     * filter are in the query string, so each works without JavaScript and a
     * link keeps them.
     */
    public function show(Request $request, string $gameId): View
    {
        $replay = ReplayPage::load($gameId);
        abort_if($replay === null, 404);

        $tab = (string) $request->query('tab', 'summary');
        if (! isset(ReplayPage::TABS[$tab]) || ($tab === 'economy' && $replay['history'] === null)) {
            $tab = 'summary';
        }
        $show = (string) $request->query('show', 'all');
        $minutes = trim((string) $request->query('minutes', ''));

        return view('replays.show', [
            'replay' => $replay,
            'tab' => $tab,
            'show' => isset(ReplayPage::SHOW[$show]) ? $show : 'all',
            // Coilbox's `parseCutMinutes`: anything that is not a number from zero up is the whole match.
            'minutes' => is_numeric($minutes) && (float) $minutes >= 0 ? (float) $minutes : null,
        ]);
    }

    /** The order heat drawn for the map card, one pixel per grid cell. */
    public function heat(string $gameId): Response
    {
        $heat = ReplayPage::heat($gameId);
        abort_if($heat === null, 404);
        $png = HubCache::remember('replays:heat:'.md5(json_encode($heat)), [], HubCache::WEEKS, fn () => base64_encode(HeatPicture::png($heat)));

        return response(base64_decode($png), 200, [
            'Content-Type' => 'image/png',
            // The link carries the heat's hash, so a changed heat is a new address.
            'Cache-Control' => 'public, max-age='.HubCache::WEEKS,
        ]);
    }
}
