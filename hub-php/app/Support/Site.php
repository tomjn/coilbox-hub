<?php

namespace App\Support;

/** The hub's own address, `siteUrl()` and `importOrigin()` in `lib/site.ts`. */
final class Site
{
    /** The hub's origin with no trailing slash, from `APP_URL`. */
    public static function url(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** The origin an import link carries. The same as {@see url()} here: there are no preview deployments. */
    public static function importOrigin(): string
    {
        return self::url();
    }

    public static function absolute(string $path): string
    {
        return self::url().'/'.ltrim($path, '/');
    }
}
