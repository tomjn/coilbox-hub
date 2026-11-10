<?php

namespace App\Http\Controllers\Games;

use App\Domain\Games\Cached;
use App\Domain\Games\CompareUnits;
use App\Domain\Games\Js;
use App\Domain\Games\Labels;
use App\Domain\Games\Stats;
use App\Domain\Games\UnitRelease;
use App\Http\Controllers\Controller;
use App\Support\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * One unit's page and its comparisons, `app/games/[shortname]/units/[unit]`.
 * The author snippet form is left out, since it needs web sign in.
 */
class UnitController extends Controller
{
    /** The unit for its latest facts, or as `<unit>@<release>` says. */
    public function show(Request $request, string $shortname, string $unit): View|Response
    {
        // next.config.ts moved `?v=` into the path. Next keeps the query on
        // the redirect and writes the release into the path unencoded.
        $asked = $request->query('v');
        if (is_string($asked) && $asked !== '') {
            // Raw query string and a relative Location, as Next sends them.
            $path = strtok((string) $request->server('REQUEST_URI'), '?');
            $target = $path.'@'.$asked.'?'.$request->server('QUERY_STRING');

            return response('', 308, ['Location' => $target, 'Refresh' => '0;url='.$target]);
        }

        $read = UnitRelease::readUnitSegment($unit);
        $v = $read['release'] ?? null;
        $game = Cached::gamePage($shortname);
        $loaded = Cached::unitPage($shortname, $read['unit'], $v);
        if ($loaded === null) {
            abort(404);
        }

        $page = $loaded['page'];
        $label = $page['full_name'] ?? $page['unit_name'];
        $gameName = $game !== null ? Labels::gameTitle($game) : $shortname;

        return view('games.unit', [
            'title' => $label.' - '.$gameName,
            'description' => 'Stats, builders, build options and renders for '.$label.', as '.$gameName.' ships it.',
            'canonical' => Site::absolute('/games/'.$shortname.'/units/'.UnitRelease::unitSegment($page['unit_name'])),
            'shortname' => $shortname,
            'gameName' => $gameName,
            'page' => $page,
            'label' => $label,
            'v' => $v,
            'renders' => $loaded['renders'],
            'buildpic' => $loaded['buildpic'],
            'buildPictures' => $loaded['buildPictures'],
        ]);
    }

    /**
     * Two releases of this unit (`left` and `right`), or this unit beside
     * another (`with`, and `release`). `with` wins when both are present.
     */
    public function compare(Request $request, string $shortname, string $unit): View
    {
        $with = $this->first($request, 'with');
        if ($with !== null) {
            return $this->compareUnits($shortname, $unit, $with, $this->first($request, 'release'));
        }

        $left = $this->first($request, 'left');
        $right = $this->first($request, 'right');
        // No pair picked is somebody who followed the breadcrumb without
        // using the form.
        if ($left === null || $left === '' || $right === null || $right === '') {
            abort(404);
        }

        $game = Cached::gamePage($shortname);
        $comparison = Cached::unitCompare($shortname, $unit, $left, $right);
        if ($game === null || $comparison === null) {
            abort(404);
        }

        $gameName = Labels::gameTitle($game);
        $label = $comparison['left']['full_name'] ?? $comparison['right']['full_name'] ?? $comparison['unit_name'];

        return view('games.compare', [
            'mode' => 'releases',
            'title' => 'Compare '.$label.' - '.$gameName,
            'shortname' => $shortname,
            'unit' => $unit,
            'gameName' => $gameName,
            'unitLabel' => $label,
            'left' => $left,
            'right' => $right,
            'comparison' => $comparison,
            'changed' => count(array_filter($comparison['rows'], fn (array $row) => $row['changed'])),
        ]);
    }

    private function compareUnits(string $shortname, string $unit, ?string $asked, ?string $askedRelease): View
    {
        // A hidden game is invisible at every level, as on the game's own page.
        $game = Cached::gamePage($shortname);
        $labels = Cached::unitNameLabels($shortname);
        $self = $labels[Js::lower($unit)] ?? null;
        if ($game === null || $self === null || $self['name'] !== $unit) {
            abort(404);
        }

        $gameName = Labels::gameTitle($game);
        $resolved = CompareUnits::resolveWithUnit($asked, $unit, array_values($labels));
        $found = $resolved['kind'] === 'found';
        $base = [
            'shortname' => $shortname,
            'unit' => $unit,
            'gameName' => $gameName,
            'unitLabel' => $self['label'],
            'resolved' => $resolved,
            'asked' => $asked,
            'askedRelease' => $askedRelease,
            'pickerCurrent' => $found ? $resolved['unit']['name'] : ($asked !== null ? Js::trim($asked) : null),
            'pickerLabel' => $found ? 'Compare with a different unit' : 'Compare with',
            'title' => 'Compare '.$self['label'].' with '.($found ? $resolved['unit']['label'] : 'another unit').' - '.$gameName,
        ];

        if (! $found) {
            return view('games.compare', $base + ['mode' => 'units-unresolved']);
        }

        $other = $resolved['unit'];
        $pair = Cached::unitPair($shortname, $unit, $other['name']);
        if ($pair === null) {
            return view('games.compare', $base + ['mode' => 'units-unread']);
        }

        [$a, $b] = $pair['units'];
        $held = fn (array $side) => array_map(fn (array $revision) => $revision['version'], $side['revisions']);
        $pick = CompareUnits::pickCompareRelease(Cached::gameReleases($shortname), $held($a), $held($b), $askedRelease === '' ? null : $askedRelease);

        $base += ['other' => $other, 'a' => $a, 'b' => $b, 'pick' => $pick];
        if ($pick['release'] === null) {
            return view('games.compare', $base + ['mode' => 'units-no-release']);
        }

        $statsAt = fn (array $side) => collect($side['revisions'])->firstWhere('version', $pick['release'])['stats'] ?? [];
        $rows = Stats::compareStatRows($statsAt($a), $statsAt($b));

        return view('games.compare', $base + [
            'mode' => 'units',
            'release' => $pick['release'],
            'pictures' => $pair['pictures'],
            'rows' => $rows,
            'changed' => count(array_filter($rows, fn (array $row) => $row['changed'])),
        ]);
    }

    /**
     * The first value of a query key, read from the raw query string because
     * Laravel keeps the last of a repeated key and turns an empty value into
     * null. Null here means the key is absent.
     */
    private function first(Request $request, string $key): ?string
    {
        foreach (explode('&', (string) $request->server('QUERY_STRING')) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (urldecode($name) === $key) {
                return urldecode($value);
            }
        }

        return null;
    }
}
