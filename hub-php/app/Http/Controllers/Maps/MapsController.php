<?php

namespace App\Http\Controllers\Maps;

use App\Auth\HubSession;
use App\Domain\Downloads\Downloads;
use App\Domain\Gallery\SearchParams;
use App\Domain\Maps\Labels;
use App\Domain\Maps\Listing;
use App\Domain\Maps\MapEdits;
use App\Domain\Maps\MapPage;
use App\Domain\Replays\HeatPicture;
use App\Domain\Replays\MapTotals;
use App\Domain\Replays\ReplayList;
use App\Http\Controllers\Controller;
use App\Support\Nav;
use App\Support\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * `/maps` and `/map/{slug}`. The Next app served `/maps` with no filter key
 * from the CDN and rewrote a filtered request to `/maps/filtered`. Here one
 * action reads the query either way, since unknown keys are dropped anyway.
 */
class MapsController extends Controller
{
    public function index(Request $request): View
    {
        $filters = Listing::parseFilters(SearchParams::fromRequest($request)->toRecord());
        $page = Listing::page($filters);

        return view('maps.index', [
            'filters' => $filters,
            'maps' => $page['maps'],
            'count' => $page['count'],
            'error' => $page['error'],
            'pictures' => $page['pictures'],
            'sections' => Listing::isLanding($filters) ? ['featured' => Listing::section('featured'), 'uploaded' => Listing::section('uploaded')] : [],
            'lastPage' => max(1, (int) ceil($page['count'] / Listing::PAGE_SIZE)),
        ]);
    }

    /** Everything the catalog holds about one map. A takedown is not found, like a map nobody has heard of. */
    public function show(Request $request, string $slug): View
    {
        $page = MapPage::cached($slug);
        abort_if($page === null, 404);

        $facts = $page['facts'];
        $title = Labels::title($facts['display_name'] ?? null, $page['mapName'], $page['mapVersion'] ?? null);
        // How big it is and how many it takes, then the archive's own words.
        $measures = implode(', ', array_filter([
            Labels::size($facts['width_elmos'], $facts['height_elmos']),
            Labels::players(count($facts['points']['start'] ?? [])),
        ]));
        $description = implode(' - ', array_filter([$measures, $facts['description'] ?? null]));

        // Decided per visitor, outside the cached page. A signed out visitor
        // costs no database call. Someone who can edit because they own it is
        // not asked whether they want to own it. A moderator can edit every
        // map, so is still asked, or they could never claim their own.
        $canEdit = Nav::current() !== null && MapEdits::canEdit(HubSession::client(), (string) $page['mapId']);
        $ownershipTargets = Nav::current() !== null && (! $canEdit || Nav::moderates()) ? MapEdits::ownershipTargets($page) : [];

        // Counted outside the cached page, which a new replay does not refresh.
        $totals = MapTotals::page($slug, $page['mapName'], $facts, $page['picture']);
        $heat = (string) $request->query('heat', '');

        return view('maps.show', $page + [
            'slug' => $slug,
            'canEdit' => $canEdit,
            'ownershipTargets' => $ownershipTargets,
            'replayCount' => ReplayList::countOf('map', $page['mapName']),
            'replayTotals' => $totals,
            // The map card's heat layer, the first it has when the address names none it has.
            'heat' => isset($totals['card']['layers'][$heat]) ? $heat : array_key_first($totals['card']['layers'] ?? []),
            // Read outside the cached page, which a download ping never refreshes.
            'downloadCounts' => Downloads::counts('map', $page['mapId']),
            'title' => $title,
            'description' => $description,
            'ogTitle' => $title,
            'ogType' => 'article',
            'origin' => Site::importOrigin(),
        ]);
    }

    /** One of the map's replay heat layers drawn for its card, one pixel per grid cell. A taken down map has none. */
    public function heat(Request $request, string $slug): Response
    {
        $page = MapPage::cached($slug);
        abort_if($page === null, 404);
        $layer = MapTotals::heat($page['mapName'], $page['facts'], (string) $request->query('layer', 'orders'));
        abort_if($layer === null, 404);

        return HeatPicture::response($layer);
    }
}
