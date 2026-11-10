<?php

namespace App\Domain\Og;

use App\Domain\Assets\StagedGameArt;
use App\Domain\Gallery\ItemPage;
use App\Domain\Gallery\Labels as GalleryLabels;
use App\Domain\Games\Cached as GamesCached;
use App\Domain\Games\GameArt;
use App\Domain\Games\Labels as GameLabels;
use App\Domain\Maps\Labels as MapLabels;
use App\Domain\Maps\MapPage;
use App\Supabase\Supabase;
use App\Support\HubCache;
use App\Support\Site;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The link preview for each page, from the `opengraph-image.tsx` routes and
 * `lib/cache/card.ts`. Each card is drawn once and its PNG held under the
 * tags of what it shows, so an edit clears the card with the page.
 *
 * Each reads through the loader its page uses, so a withdrawn item, a map the
 * hub may not publish or a hidden game gets the generic card rather than
 * advertising what the site does not show.
 */
final class Cards
{
    /** Logo types GD can draw. The Next renderer could not decode WebP, GD can. */
    private const LOGO_TYPES = ['image/png', 'image/apng', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * The card a page links to, by its path. Next took the nearest
     * `opengraph-image` up the route tree, so every page under an item, a map
     * or a game uses that card and every other page uses the hub's own.
     */
    public static function urlFor(string $path): string
    {
        $path = trim($path, '/');
        if (preg_match('#^(item|map|games)/([^/]+)#', $path, $match)) {
            return Site::absolute($match[1].'/'.$match[2].'/opengraph-image');
        }

        return Site::absolute('opengraph-image');
    }

    public static function home(): string
    {
        return HubCache::remember('og:home', [], HubCache::WEEKS, fn () => Card::home());
    }

    public static function item(string $id): string
    {
        return HubCache::remember('og:item:'.$id, [HubCache::ITEMS, HubCache::item($id)], HubCache::HOURS, function () use ($id) {
            $item = ItemPage::public($id)['item'] ?? null;
            if ($item === null) {
                return Card::standard('', 'Coilbox Hub', '', 'Coilbox Hub');
            }
            $facts = implode('  ·  ', array_filter([$item['game_name'] ?? null, $item['map_name'] ?? null]));
            $credit = ($item['author_name'] ?? null) ? 'by '.$item['author_name'] : 'Coilbox Hub';

            return Card::standard(GalleryLabels::item($item['kind'], $item['mode'] ?? null), (string) $item['title'], $facts, $credit);
        });
    }

    public static function map(string $slug): string
    {
        return HubCache::remember('og:map:'.$slug, [HubCache::MAPS, HubCache::map($slug)], HubCache::WEEKS, function () use ($slug) {
            $page = MapPage::cached($slug);
            if ($page === null) {
                return Card::standard('Map', 'Coilbox Hub', '', 'Coilbox Hub');
            }
            $facts = $page['facts'];
            $measures = implode('  ·  ', array_filter([
                MapLabels::size($facts['width_elmos'], $facts['height_elmos']),
                MapLabels::players(count($facts['points']['start'] ?? [])),
            ]));
            $credit = implode(', ', array_map(fn ($author) => $author['name'], $facts['authors'] ?? []));

            return Card::standard('Map', MapLabels::title($facts['display_name'] ?? null, $page['mapName']), $measures, $credit !== '' ? 'by '.$credit : 'Coilbox Hub');
        });
    }

    public static function game(string $shortname): string
    {
        return HubCache::remember('og:game:'.$shortname, [HubCache::GAMES, HubCache::ASSETS], HubCache::WEEKS, function () use ($shortname) {
            $page = GamesCached::gamePage($shortname);
            if ($page === null) {
                return Card::standard('Game', 'Coilbox Hub', '', 'Coilbox Hub');
            }

            return Card::standard('Game', GameLabels::gameTitle($page), GameLabels::gameCountLabel($page), 'Coilbox Hub', self::logo($page));
        });
    }

    /**
     * The game's logo bytes, or null to draw the card without one. A logo still
     * in the staging bucket is read from there, through the same checks its
     * route makes, rather than by the hub fetching its own URL.
     *
     * @param  array<string, mixed>  $page
     */
    private static function logo(array $page): ?string
    {
        try {
            if (($page['logo_staged_tier'] ?? null) === 'bucket' && ($page['logo_hash'] ?? null)) {
                $art = StagedGameArt::fetch(Supabase::anon(), Supabase::admin(), $page['shortname'], 'logo', $page['logo_hash']);

                return isset($art['bytes']) && in_array($art['mime'], self::LOGO_TYPES, true) ? $art['bytes'] : null;
            }
            $url = GameArt::fromRow($page, 'logo');
            if ($url === null) {
                return null;
            }
            $response = Http::timeout(10)->get($url);
            if (! $response->ok()) {
                return null;
            }
            $type = strtolower(trim(explode(';', $response->header('Content-Type') ?: 'image/png')[0]));

            return in_array($type, self::LOGO_TYPES, true) ? $response->body() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
