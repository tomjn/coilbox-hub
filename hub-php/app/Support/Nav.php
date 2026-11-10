<?php

namespace App\Support;

/**
 * Who the header says is signed in, the PHP side of `hub_nav` in the Next app.
 *
 * The Next app drew the header in the browser so the CDN could serve one page
 * to everybody. Here PHP renders every page and caches the data rather than
 * the HTML, so the header is drawn on the server from the session. The sign in
 * work stores `['id', 'name', 'moderation']` under the `nav` session key.
 * `moderation` is `granted`, `verify`, `enrol` or `none`.
 */
final class Nav
{
    /** @return array{id: string, name: string|null, moderation: string}|null */
    public static function current(): ?array
    {
        $nav = session('nav');

        return is_array($nav) && isset($nav['id']) ? $nav : null;
    }

    public static function moderates(): bool
    {
        $nav = self::current();

        return $nav !== null && ($nav['moderation'] ?? 'none') !== 'none';
    }
}
