<?php

namespace App\Http\Controllers\Gallery;

use App\Domain\Gallery\GalleryQuery;
use App\Domain\Gallery\Labels;
use App\Domain\Gallery\Listings;
use App\Domain\Gallery\SearchParams;
use App\Http\Controllers\Controller;
use App\Support\Site;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The landing page and `/gallery`, from `app/page.tsx` and `app/gallery`. */
class GalleryController extends Controller
{
    public function home(): View
    {
        return view('gallery.home', [
            'newest' => Listings::newest(),
            'featured' => Listings::featured(),
            'filters' => GalleryQuery::parseFilters([]),
            'origin' => Site::importOrigin(),
        ]);
    }

    /**
     * The Next app served the bare gallery from the CDN and rewrote a request
     * carrying a filter to `/gallery/filtered`. Here one action reads the query
     * either way. Parsing is forgiving: a typo in a shared link shows the
     * unfiltered gallery rather than an error.
     */
    public function index(Request $request): View
    {
        $filters = GalleryQuery::parseFilters(SearchParams::fromRequest($request)->toRecord());
        $page = Listings::page($filters);
        $accounts = array_map(fn (string $id) => ['id' => $id, 'name' => Listings::accountName($id) ?? 'Unknown account'], $filters->account);

        return view('gallery.index', [
            'title' => 'Gallery',
            'description' => Labels::kindsPlural().' shared by other players.',
            'filters' => $filters,
            'page' => $page,
            'facets' => Listings::facets(),
            'accounts' => $accounts,
            'lastPage' => max(1, (int) ceil($page['count'] / GalleryQuery::PAGE_SIZE)),
            'origin' => Site::importOrigin(),
        ]);
    }
}
