<?php

namespace App\Domain\Gallery;

use App\Domain\Assets\AssetResolver;
use App\Domain\Games\Units;
use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * What a page of gallery cards needs beyond the listing rows, each in one
 * batched read for the whole page. From the Next app's `cardPictures.ts`,
 * `cardShapes.ts`, `cardCounts.ts`, `cardTitles.ts` and `itemCardArt.ts`.
 *
 * A card's galaxy, run or layout is drawn in the browser from the parts read
 * here (see `resources/js/gallery`), because the generators are large vendored
 * TypeScript. PHP reads only the JSON paths those generators need.
 */
final class Cards
{
    /** Only these kinds draw the map they name. A setup pack's map is only the first of several. */
    private const KINDS_WITH_MAP_ART = ['scenario', 'preset'];

    /** The challenge modes the hub can rebuild. */
    private const DRAWN_MODES = ['conquest', 'warpath'];

    private const SHAPE_COLUMNS = 'id,settings:container->payload->settings,name:container->payload->>name,ordered:container->payload->ordered,buildings:container->payload->buildings,terrain:container->payload->terrain,footprints:container->payload->footprints';

    private const COUNT_COLUMNS = 'id,overrides:container->payload->edits->overrides,clone_count,menus:container->payload->edits->menus,text:container->payload->edits->text,disabled:container->payload->edits->disabled';

    /** Six characters of the id, six hex digits, enough to tell twins apart on one page. */
    private const TAIL_LENGTH = 6;

    /** @param array<string, mixed> $item */
    public static function namesItsMap(array $item): bool
    {
        return in_array($item['kind'], self::KINDS_WITH_MAP_ART, true) && ($item['map_name'] ?? '') !== '';
    }

    /** @param array<string, mixed> $item */
    public static function hasShape(array $item): bool
    {
        if ($item['kind'] === 'challenge') {
            return $item['mode'] !== null && in_array($item['mode'], self::DRAWN_MODES, true);
        }

        return $item['kind'] === 'blueprint';
    }

    /**
     * Everything a page of cards draws, keyed for lookup by the card.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{pictures: array<string, array<string, mixed>>, shapes: array<string, array<string, mixed>>, counts: array<string, array<string, int>>, titles: array<string, array{title: string, tail: string|null}>}
     */
    public static function forPage(Client $supabase, array $items): array
    {
        return [
            'pictures' => self::mapPictures($supabase, $items),
            'shapes' => self::shapes($supabase, $items),
            'counts' => self::counts($supabase, $items),
            'titles' => self::titles($items),
        ];
    }

    /**
     * A resolved map picture per scenario or preset that names one, keyed on
     * the map name. No footprint: every card draws in the same fixed box.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, array<string, mixed>>
     */
    public static function mapPictures(Client $supabase, array $items): array
    {
        $names = array_values(array_unique(array_map(fn ($item) => $item['map_name'], array_filter($items, self::namesItsMap(...)))));
        if ($names === []) {
            return [];
        }
        $identities = array_map(fn (string $name) => AssetResolver::map($name), $names);
        $held = AssetResolver::fetchHeldAssets($supabase, $identities);

        $pictures = [];
        foreach ($identities as $i => $identity) {
            $pictures[$names[$i]] = AssetResolver::resolveAsset($identity, $held);
        }

        return $pictures;
    }

    /**
     * The parts each challenge or blueprint card's drawing is rebuilt from,
     * keyed on the item id, with a blueprint's building roles for its game.
     * A row nothing came back for is absent, and the card keeps its plate.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, array{kind: string, mode: string|null, parts: array<string, mixed>, roles: array<string, string>}>
     */
    public static function shapes(Client $supabase, array $items): array
    {
        $drawable = array_values(array_filter($items, self::hasShape(...)));
        if ($drawable === []) {
            return [];
        }
        try {
            $rows = $supabase->from('item')->select(self::SHAPE_COLUMNS)->in('id', array_column($drawable, 'id'))->get();
        } catch (SupabaseException) {
            return [];
        }
        $parts = array_column($rows, null, 'id');

        $rolesByGame = [];
        $shapes = [];
        foreach ($drawable as $item) {
            $part = $parts[$item['id']] ?? null;
            if ($part === null) {
                continue;
            }
            unset($part['id']);
            $roles = [];
            if ($item['kind'] === 'blueprint' && ($item['game_key'] ?? null) !== null) {
                $rolesByGame[$item['game_key']] ??= self::rolesFor($supabase, $item['game_key']);
                $roles = self::ownRoles($rolesByGame[$item['game_key']], $part['buildings'] ?? null);
            }
            $shapes[$item['id']] = ['kind' => $item['kind'], 'mode' => $item['mode'], 'parts' => $part, 'roles' => $roles];
        }

        return $shapes;
    }

    /**
     * How much each mod-project on the page changes. The clone count comes
     * from the database, since a clone's whole unit table is large.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, array{unitsTouched: int, fields: int, clones: int, menuOps: int, disabled: int}>
     */
    public static function counts(Client $supabase, array $items): array
    {
        $projects = array_values(array_filter($items, fn ($item) => $item['kind'] === 'mod-project'));
        if ($projects === []) {
            return [];
        }
        try {
            $rows = $supabase->from('item')->select(self::COUNT_COLUMNS)->in('id', array_column($projects, 'id'))->get();
        } catch (SupabaseException) {
            return [];
        }
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['id']] = [...ModProject::counts(ModProject::parseEdits($row)), 'clones' => (int) ($row['clone_count'] ?? 0)];
        }

        return $counts;
    }

    /**
     * The title each card shows. A title repeated on the page, trimmed and
     * case folded, gets the tail of its id so twins can be told apart (#311).
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, array{title: string, tail: string|null}>
     */
    public static function titles(array $items): array
    {
        $normalise = fn (string $title) => mb_strtolower(trim($title));
        $seen = [];
        foreach ($items as $item) {
            $key = $normalise($item['title']);
            $seen[$key] = ($seen[$key] ?? 0) + 1;
        }
        $titles = [];
        foreach ($items as $item) {
            $titles[$item['id']] = [
                'title' => $item['title'],
                'tail' => $seen[$normalise($item['title'])] > 1 ? substr($item['id'], -self::TAIL_LENGTH) : null,
            ];
        }

        return $titles;
    }

    /**
     * The art a card draws: `map` with the resolved picture, `art` when the
     * browser can draw a galaxy, run or layout, or the `plate` otherwise.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $picture
     * @param  array<string, mixed>|null  $shape
     */
    public static function artChoice(array $item, ?array $picture, ?array $shape): string
    {
        if (self::namesItsMap($item) && $picture !== null) {
            return 'map';
        }

        // Only a challenge draws a galaxy or run, and only a blueprint draws a layout.
        $drawn = $shape !== null && in_array($item['kind'], ['challenge', 'blueprint'], true) && ($shape['kind'] ?? null) === $item['kind'];

        return $drawn ? 'art' : 'plate';
    }

    /** "1 unit · 3 fields", leaving out what nothing changed. @param array<string, int> $counts */
    public static function projectCountLine(array $counts): string
    {
        $labels = [
            ['unitsTouched', 'unit', 'units'],
            ['fields', 'field', 'fields'],
            ['clones', 'clone', 'clones'],
            ['menuOps', 'menu edit', 'menu edits'],
            ['disabled', 'disabled', 'disabled'],
        ];
        $parts = [];
        foreach ($labels as [$key, $singular, $plural]) {
            $n = $counts[$key] ?? 0;
            if ($n > 0) {
                $parts[] = $n.' '.($n === 1 ? $singular : $plural);
            }
        }

        return implode(' · ', $parts);
    }

    /** @return array<string, string> */
    private static function rolesFor(Client $supabase, string $game): array
    {
        try {
            return Units::unitRoles($supabase, $game);
        } catch (SupabaseException) {
            return [];
        }
    }

    /**
     * Only this layout's defs, so a page does not carry a game's roles per card.
     *
     * @param  array<string, string>  $roles
     * @return array<string, string>
     */
    private static function ownRoles(array $roles, mixed $buildings): array
    {
        $own = [];
        foreach (is_array($buildings) ? $buildings : [] as $building) {
            $def = is_array($building) && is_string($building['def'] ?? null) ? mb_strtolower($building['def']) : null;
            if ($def !== null && isset($roles[$def])) {
                $own[$def] = $roles[$def];
            }
        }

        return $own;
    }
}
