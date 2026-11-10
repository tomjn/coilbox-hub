<?php

namespace App\Http\Controllers\Games;

use App\Domain\Games\Cached;
use App\Domain\Games\Js;
use App\Domain\Games\Labels;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The build tree, `app/games/[shortname]/tree`. The Next app split it into a
 * static page and a `filtered` one reached by a rewrite when the query carried
 * `v` or `faction`. Here one action reads those two keys, and only when one of
 * them is present, so a stray query on the plain URL changes nothing.
 */
class TreeController extends Controller
{
    public function show(Request $request, string $shortname): View
    {
        $game = Cached::gamePage($shortname);
        if ($game === null) {
            abort(404);
        }

        $version = null;
        $factionParam = null;
        $query = self::firstValues($request);
        if (isset($query['v']) || isset($query['faction'])) {
            $version = $query['v'] ?? null;
            $trimmed = trim($query['faction'] ?? '');
            $factionParam = $trimmed !== '' ? $trimmed : null;
        }

        $factions = Cached::gameFactions($shortname);
        $faction = $factionParam ?? ($factions[0]['key'] ?? null);

        $tree = Cached::tree($shortname, $version, $faction);
        if ($tree === null) {
            abort(404);
        }

        $byName = [];
        foreach ($tree['factions'] as $block) {
            foreach ($block['units'] as $node) {
                $byName[$node['name']] = $node;
            }
        }
        foreach ($tree['ungrouped'] as $node) {
            $byName[$node['name']] = $node;
        }

        $name = Labels::gameTitle($game);

        return view('games.tree', [
            'shortname' => $shortname,
            'gameTitle' => $name,
            'title' => $name.' build tree',
            'metaDescription' => 'What every faction in '.$name.' can reach, from its start units.',
            'tree' => $tree,
            'byName' => $byName,
            'pictures' => Cached::treeUnitPictures($shortname, array_map('strval', array_keys($byName))),
            'claims' => self::claims($tree, $byName),
            'version' => $version,
            'toggles' => count($factions) < 2 ? null : array_map(fn (array $option) => [
                'key' => $option['key'],
                'label' => $option['name'],
                'href' => '/games/'.$shortname.'/tree?'.Js::searchParams(
                    ($version !== null && $version !== '' ? ['v' => $version] : []) + ['faction' => $option['key']],
                ),
                'active' => $faction === $option['key'],
            ], $factions),
        ]);
    }

    /**
     * The first value of each query key. Next reads a repeated key as a list
     * and takes its head, where PHP's own parsing keeps the last.
     *
     * @return array<string, string>
     */
    private static function firstValues(Request $request): array
    {
        $values = [];
        foreach (explode('&', (string) $request->server->get('QUERY_STRING')) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = [...explode('=', $pair, 2), ''];
            $key = urldecode($key);
            if (! array_key_exists($key, $values)) {
                $values[$key] = urldecode($value);
            }
        }

        return $values;
    }

    /** How many levels deep React ran a walk before it queued the rest, measured against 8 games. */
    private const WALK_DEPTH = 5;

    /**
     * Which builds each unit unfolds, so a unit opens once on the page and
     * every later mention is a plain link (#266). The React page claimed units
     * as its components ran. It ran each subtree depth first down to
     * WALK_DEPTH levels and queued anything deeper until the rest had run, so
     * a unit two branches share goes to the shallower claim. The same order
     * here keeps the same parent winning.
     *
     * @param  array{factions: list<array<string, mixed>>, ungrouped: list<array<string, mixed>>}  $tree
     * @param  array<string, array<string, mixed>>  $byName
     * @return array<string, array<string, true>> parent name => child names it unfolds
     */
    private static function claims(array $tree, array $byName): array
    {
        $blocks = [];
        foreach ($tree['factions'] as $block) {
            foreach ($block['units'] as $unit) {
                if ($unit['name'] === $block['root']) {
                    $blocks[] = [$unit];
                    break;
                }
            }
        }
        if ($tree['ungrouped'] !== []) {
            $blocks[] = $tree['ungrouped'];
        }

        $claimed = [];
        foreach ($blocks as $roots) {
            foreach ($roots as $root) {
                $claimed[(string) $root['name']] = true;
            }
        }

        $claims = [];
        $queued = [];
        $run = function (array $node, int $depth) use (&$run, &$claimed, &$claims, &$queued, $byName): void {
            $name = (string) $node['name'];
            $unfolded = [];
            foreach ($node['builds'] as $childName) {
                $child = $byName[$childName] ?? null;
                if ($child === null || isset($claimed[(string) $child['name']])) {
                    continue;
                }
                $claimed[(string) $child['name']] = true;
                $claims[$name][(string) $child['name']] = true;
                // A unit that builds nothing is a leaf row and runs nothing.
                if (count($child['builds']) > 0) {
                    $unfolded[] = $child;
                }
            }
            foreach ($unfolded as $child) {
                if ($depth + 1 >= self::WALK_DEPTH) {
                    $queued[] = $child;
                } else {
                    $run($child, $depth + 1);
                }
            }
        };

        foreach ($blocks as $roots) {
            foreach ($roots as $root) {
                $run($root, 0);
            }
        }
        for ($i = 0; $i < count($queued); $i++) {
            $run($queued[$i], 0);
        }

        return $claims;
    }
}
