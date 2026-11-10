<?php

namespace App\Http\Controllers\Games;

use App\Domain\Games\Cached;
use App\Domain\Games\GameArt;
use App\Domain\Games\Js;
use App\Domain\Games\Labels;
use App\Domain\Games\RichText;
use App\Domain\Games\Query;
use App\Http\Controllers\Controller;
use App\Support\Nav;
use Illuminate\View\View;

/** The games listing and one game's page. */
class GamesController extends Controller
{
    public function index(): View
    {
        $listing = Cached::gamesListing();
        $rows = Query::withoutMutators($listing['games']);
        $sides = Cached::gameSides(array_map(fn (array $game) => $game['shortname'], $rows));

        // Already featured first, so this is a split rather than a second sort.
        $featured = array_values(array_filter($rows, fn (array $game) => ($game['featured_at'] ?? null) !== null));

        return view('games.index', [
            'error' => $listing['error'],
            'rows' => $rows,
            'sides' => $sides,
            'featured' => $featured,
            'rest' => array_slice($rows, count($featured)),
        ]);
    }

    /** One game's page, `app/games/[shortname]/page.tsx`. */
    public function show(string $shortname): View
    {
        $page = Cached::gamePage($shortname);
        if ($page === null) {
            abort(404);
        }

        $title = Labels::gameTitle($page);
        $commanders = Cached::gameSides([$page['shortname']])[$page['shortname']]['commanders'] ?? [];
        // A comparison needs two releases to compare.
        $releaseCount = count(Cached::gameReleases($page['shortname']));
        $factionSlots = false;
        foreach ($page['factions'] as $faction) {
            if ($faction['logo_path'] !== null || isset($commanders[$faction['key']])) {
                $factionSlots = true;
            }
        }
        $plain = $page['description'] !== null ? RichText::toPlainText($page['description']) : null;

        $onward = [
            ['href' => '/games/'.$page['shortname'].'/units', 'name' => 'Unit encyclopedia', 'detail' => Labels::gameCountLabel($page)],
            ['href' => '/games/'.$shortname.'/tree', 'name' => 'Build tree', 'detail' => 'What each faction can reach from its start units'],
        ];
        if ($releaseCount >= 2) {
            $onward[] = ['href' => '/games/'.$shortname.'/changes', 'name' => 'Release changes', 'detail' => 'What each release added, removed and changed'];
        }
        $onward[] = ['href' => '/gallery?game='.Js::encodeURIComponent($shortname), 'name' => 'Community items', 'detail' => Labels::itemCardLabel($page['item_count'])];

        return view('games.show', [
            'page' => $page,
            'title' => $title,
            'metaDescription' => $page['description'] ? $plain : $page['faction_count'].' factions and '.$page['unit_count'].' units on the hub.',
            'banner' => GameArt::fromRow($page, 'banner'),
            'logo' => GameArt::fromRow($page, 'logo'),
            'commanders' => $commanders,
            'factionSlots' => $factionSlots,
            'describes' => $plain !== null && Labels::saysMoreThanName($page, $plain),
            'onward' => $onward,
            // Who is looking decides two things only: the owner or a moderator
            // gets the pen and the hide switch, and on a game nobody owns a
            // signed in visitor gets the ownership ask. Drawing a control is
            // all this decides. The action behind each asks the database again.
            'ownerControls' => self::shownTo($page['owner_user_id'] ?? null),
            'ownershipAsk' => ($page['owner_user_id'] ?? null) === null && Nav::current() !== null,
        ]);
    }

    /** `ShownTo` with `owner` and `moderators`: the named owner, or a moderator who has entered their code. */
    public static function shownTo(?string $owner): bool
    {
        $nav = Nav::current();
        if ($nav === null) {
            return false;
        }

        return ($owner !== null && $owner === $nav['id']) || ($nav['moderation'] ?? 'none') === 'granted';
    }
}
