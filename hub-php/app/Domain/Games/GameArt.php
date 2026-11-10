<?php

namespace App\Domain\Games;

use App\Domain\Assets\Cdn;

/**
 * Where a game's pictures are drawn from, before and after promotion, the URL
 * half of `lib/games/art.ts`. The route that serves a staged picture belongs
 * to the assets area.
 *
 * Game art sits at a fixed path that each upload overwrites, so the hub's own
 * route names the hash: `/assets/games/<shortname>/<kind>/<hash>`. That URL
 * only ever serves one set of bytes, so it can be cached for good.
 */
final class GameArt
{
    /** The kinds of picture a game row carries, which are also the column prefixes. */
    public const KINDS = ['logo', 'banner', 'card'];

    public const ROUTE_PREFIX = '/assets/games/';

    /**
     * The URL a page draws a game picture from, or null when it should draw
     * nothing. Root relative for a staged picture.
     *
     * @param  array{path: string|null, hash: string|null, staged_tier: string|null}  $art
     */
    public static function url(string $shortname, string $kind, array $art): ?string
    {
        if (($art['path'] ?? null) === null || $art['path'] === '') {
            return null;
        }
        // No staged copy: promoted, or imported straight to the durable tier.
        if ($art['staged_tier'] === null) {
            return Cdn::staticTierUrl($art['path']);
        }
        if ($art['staged_tier'] === 'bucket' && ($art['hash'] ?? null)) {
            return self::ROUTE_PREFIX.Js::encodeURIComponent($shortname).'/'.$kind.'/'.$art['hash'];
        }

        return null;
    }

    /** The URL for one kind read straight off a game row's `<kind>_path`, `_hash` and `_staged_tier` columns. */
    public static function fromRow(array $game, string $kind): ?string
    {
        return self::url($game['shortname'], $kind, [
            'path' => $game[$kind.'_path'] ?? null,
            'hash' => $game[$kind.'_hash'] ?? null,
            'staged_tier' => $game[$kind.'_staged_tier'] ?? null,
        ]);
    }
}
