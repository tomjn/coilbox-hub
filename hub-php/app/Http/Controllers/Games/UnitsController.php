<?php

namespace App\Http\Controllers\Games;

use App\Domain\Gallery\GalleryQuery;
use App\Domain\Games\Cached;
use App\Domain\Games\Js;
use App\Domain\Games\Labels;
use App\Domain\Games\Units;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Every unit a game ships, `app/games/[shortname]/units/UnitsView.tsx`.
 * Filters are query parameters and paging is ordinary links, so the URL is the
 * state and nothing needs scripting. Retired units are hidden unless
 * `?retired=1` asks for them.
 */
class UnitsController extends Controller
{
    public function index(Request $request, string $shortname): View
    {
        // A hidden game is invisible at every level, so the grid is not found
        // rather than an empty shelf that reads as nothing reported yet.
        $game = Cached::gamePage($shortname);
        if ($game === null) {
            abort(404);
        }

        $filters = Units::parseUnitGridFilters($request->query());
        $grid = Cached::unitGrid($shortname, $filters);
        $lastPage = max(1, (int) ceil($grid['count'] / Units::UNIT_PAGE_SIZE));

        $href = function (array $pairs) use ($shortname): string {
            $suffix = Js::searchParams($pairs);

            return '/games/'.$shortname.'/units'.($suffix !== '' ? '?'.$suffix : '');
        };
        $base = array_filter([
            'q' => $filters['q'],
            'retired' => $filters['retired'] ? '1' : null,
        ], fn ($value) => $value !== null);

        // Faction as toggles, each a link carrying the other filters. Choosing a
        // side restarts paging.
        $factionOptions = [['key' => '', 'label' => 'All factions', 'href' => $href($base), 'active' => $filters['faction'] === null]];
        foreach ($game['factions'] as $faction) {
            $factionOptions[] = [
                'key' => $faction['key'],
                'label' => $faction['name'],
                'href' => $href($base + ['faction' => $faction['key']]),
                'active' => $filters['faction'] === $faction['key'],
            ];
        }

        $pages = [];
        if ($lastPage > 1) {
            foreach (GalleryQuery::pageNumbers($filters['page'], $lastPage) as $step) {
                $pages[] = $step === GalleryQuery::PAGE_GAP
                    ? ['gap' => true]
                    : ['number' => $step, 'current' => $step === $filters['page'], 'href' => $href($base + array_filter([
                        'faction' => $filters['faction'],
                        'page' => $step > 1 ? (string) $step : null,
                    ], fn ($value) => $value !== null))];
            }
        }

        $name = Labels::gameTitle($game);

        return view('games.units', [
            'title' => $name.' units',
            'description' => 'Every unit '.$name.' ships, with its stats and build tree.',
            'game' => $game,
            'gameTitle' => $name,
            'shortname' => $shortname,
            'filters' => $filters,
            'retiredHeld' => Cached::retiredUnitsHeld($shortname),
            'factionOptions' => $factionOptions,
            'grid' => $grid,
            'pages' => $pages,
        ]);
    }
}
