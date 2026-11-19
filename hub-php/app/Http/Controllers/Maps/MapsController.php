<?php

namespace App\Http\Controllers\Maps;

use App\Auth\HubSession;
use App\Domain\Gallery\SearchParams;
use App\Domain\Maps\Labels;
use App\Domain\Maps\Listing;
use App\Domain\Maps\MapEdits;
use App\Domain\Maps\MapPage;
use App\Http\Controllers\Controller;
use App\Support\Nav;
use App\Support\Site;
use Illuminate\Http\Request;
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
    public function show(string $slug): View
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
        // costs no database call. Someone who can already edit is not asked
        // whether they want to own it.
        $canEdit = Nav::current() !== null && MapEdits::canEdit(HubSession::client(), (string) $page['mapId']);
        $ownershipTargets = Nav::current() !== null && ! $canEdit ? MapEdits::ownershipTargets($page) : [];

        return view('maps.show', $page + [
            'slug' => $slug,
            'canEdit' => $canEdit,
            'ownershipTargets' => $ownershipTargets,
            'title' => $title,
            'description' => $description,
            'ogTitle' => $title,
            'ogType' => 'article',
            'origin' => Site::importOrigin(),
        ]);
    }
}
