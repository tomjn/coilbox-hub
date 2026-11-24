<?php

namespace App\Domain\Links;

/** Turns a link found on a page into an absolute `http` or `https` URL. */
final class Urls
{
    public static function resolve(string $base, string $link): ?string
    {
        $link = trim($link);
        if ($link === '') {
            return null;
        }

        $base = parse_url($base);
        if ($base === false || ! isset($base['scheme'], $base['host'])) {
            return null;
        }
        $origin = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');

        if (str_starts_with($link, '//')) {
            $absolute = $base['scheme'].':'.$link;
        } elseif (preg_match('/^[a-z][a-z0-9+.-]*:/i', $link) === 1) {
            $absolute = $link;
        } elseif (str_starts_with($link, '/')) {
            $absolute = $origin.$link;
        } elseif (str_starts_with($link, '?')) {
            $absolute = $origin.($base['path'] ?? '/').$link;
        } elseif (str_starts_with($link, '#')) {
            $absolute = $origin.($base['path'] ?? '/').(isset($base['query']) ? '?'.$base['query'] : '');
        } else {
            $path = $base['path'] ?? '/';
            $absolute = $origin.substr($path, 0, (int) strrpos($path, '/') + 1).$link;
        }

        $scheme = strtolower((string) parse_url($absolute, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https' ? $absolute : null;
    }
}
