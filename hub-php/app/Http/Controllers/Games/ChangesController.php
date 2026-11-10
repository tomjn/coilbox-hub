<?php

namespace App\Http\Controllers\Games;

use App\Domain\Games\Cached;
use App\Domain\Games\Changes;
use App\Domain\Games\Labels;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What changed between two releases, `app/games/[shortname]/changes`. The Next
 * app split it into a static page and a `filtered` one reached by a rewrite
 * when the query carried `from` or `to`. Here one action reads those two keys,
 * and only when one of them is present.
 */
class ChangesController extends Controller
{
    public function show(Request $request, string $shortname): View
    {
        // A hidden game is invisible at every level, as on the game's own page.
        $game = Cached::gamePage($shortname);
        if ($game === null) {
            abort(404);
        }

        $asked = ['from' => null, 'to' => null];
        $query = self::firstValues($request);
        if (isset($query['from']) || isset($query['to'])) {
            foreach (array_keys($asked) as $key) {
                $value = trim($query[$key] ?? '');
                $asked[$key] = $value !== '' ? $value : null;
            }
        }

        $releases = Cached::gameReleases($shortname);
        $pair = Changes::resolveReleasePair($releases, $asked['from'], $asked['to']);
        $from = $pair['from'];
        $to = $pair['to'];
        $name = Labels::gameTitle($game);

        $data = [
            'shortname' => $shortname,
            'gameTitle' => $name,
            'title' => $name.' release changes',
            'metaDescription' => 'Which units '.$name.' added, removed and changed between two releases.',
            'releases' => $releases,
            'from' => $from,
            'to' => $to,
            'notices' => $pair['notes'],
            'sameRelease' => false,
            'changes' => null,
            'failed' => false,
        ];
        if ($from === null || $to === null || $from === $to) {
            return view('games.changes', [...$data, 'sameRelease' => $from !== null && $from === $to]);
        }

        if (array_search($from, $releases, true) < array_search($to, $releases, true)) {
            $data['notices'][] = $from.' was reported more recently than '.$to.', so "added" means in '.$to.' and not in '.$from.'.';
        }

        $changes = Cached::releaseChanges($shortname, $from, $to);
        if ($changes === null) {
            return view('games.changes', [...$data, 'failed' => true]);
        }

        $factions = Cached::gameFactions($shortname);

        return view('games.changes', [
            ...$data,
            'changes' => $changes,
            'labels' => Cached::unitNameLabels($shortname),
            'factionNames' => array_column($factions, 'name', 'key'),
            'added' => Changes::groupByFaction($changes['added'], $factions),
            'removed' => Changes::groupByFaction($changes['removed'], $factions),
            'changed' => Changes::groupByFaction($changes['changed'], $factions),
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
}
