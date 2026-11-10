<?php

namespace App\Domain\Assets;

/**
 * Where the durable tier is served from, `lib/assets/cdn.ts` in the Next app.
 *
 * `asset.path` is tier relative, so the host is not in the database and moving
 * it is config rather than a migration. `ASSET_CDN_BASE` overrides the default.
 */
final class Cdn
{
    /** The assets repo on GitHub Pages. A subpath, so joining must not drop `/coilbox-assets`. */
    public const DEFAULT_BASE = 'https://tomjn.github.io/coilbox-assets/';

    /** The base with exactly one trailing slash. Blank or whitespace counts as unset. */
    public static function base(): string
    {
        // `config/hub.php` has already added a slash, so a blank variable arrives as "/".
        $configured = trim(rtrim(trim((string) config('hub.asset_cdn_base', '')), '/'));

        return rtrim($configured === '' ? self::DEFAULT_BASE : $configured, '/').'/';
    }

    /**
     * The absolute URL for a tier relative path on the durable tier. Joined by
     * hand, because URL resolution would drop the subpath for a path that
     * starts with a slash.
     */
    public static function staticTierUrl(string $path): string
    {
        return self::base().ltrim($path, '/');
    }
}
