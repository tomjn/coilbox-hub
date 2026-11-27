<?php

namespace App\Http\Controllers\Replays;

use App\Domain\Gallery\SearchParams;
use App\Domain\Replays\ReplayList;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** `/replays`, the list of shared match replays. */
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
}
