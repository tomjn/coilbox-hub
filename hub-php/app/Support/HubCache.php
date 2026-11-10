<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * The PHP side of the Next app's `"use cache"` loaders and `lib/cache/tags.ts`.
 *
 * A loader caches what it read under a key and the tags it depends on. A write
 * calls {@see invalidate()} with the tags it touched. Each tag has a version
 * number, and the version of every tag is part of the cache key, so bumping a
 * tag makes every entry that named it unreachable. That works on any cache
 * store, including the file store, which has no tag support of its own.
 *
 * Tag names match `lib/cache/tags.ts`: `items`, `item:<id>`, `maps`,
 * `map:<slug>`, `games`, `unit:<shortname>/<unit>`, `assets`.
 */
final class HubCache
{
    /** The Next app's `cacheLife("hours")`. */
    public const HOURS = 3600;

    /** The Next app's `cacheLife("weeks")`. */
    public const WEEKS = 604800;

    public const ITEMS = 'items';

    public const MAPS = 'maps';

    public const GAMES = 'games';

    public const ASSETS = 'assets';

    public static function item(string $id): string
    {
        return 'item:'.$id;
    }

    public static function map(string $slug): string
    {
        return 'map:'.$slug;
    }

    public static function unit(string $shortname, string $unit): string
    {
        return 'unit:'.$shortname.'/'.$unit;
    }

    /**
     * @template T
     *
     * @param  list<string>  $tags
     * @param  Closure(): T  $load
     * @return T
     */
    public static function remember(string $key, array $tags, int $seconds, Closure $load): mixed
    {
        // Wrapped so a loader that legitimately returns null is cached too.
        $entry = Cache::remember(self::versionedKey($key, $tags), $seconds, fn () => ['value' => $load()]);

        return $entry['value'];
    }

    /** @param list<string> $tags */
    public static function invalidate(array $tags): void
    {
        foreach (array_unique($tags) as $tag) {
            Cache::forever(self::tagKey($tag), self::newVersion());
        }
    }

    /** @param list<string> $tags */
    private static function versionedKey(string $key, array $tags): string
    {
        $versions = [];
        foreach ($tags as $tag) {
            $versions[] = $tag.'='.self::version($tag);
        }

        return 'hub:'.sha1($key.'|'.implode('|', $versions));
    }

    /**
     * A random version, never a counter. Memcached may evict a version key at
     * any time, and a counter would restart at a number older entries were
     * stored under, bringing stale pages back. A fresh random version for a
     * missing key matches nothing already stored.
     */
    private static function version(string $tag): string
    {
        $version = Cache::get(self::tagKey($tag));
        if (! is_string($version)) {
            $version = self::newVersion();
            Cache::forever(self::tagKey($tag), $version);
        }

        return $version;
    }

    private static function newVersion(): string
    {
        return bin2hex(random_bytes(8));
    }

    private static function tagKey(string $tag): string
    {
        return 'hub-tag:'.sha1($tag);
    }
}
