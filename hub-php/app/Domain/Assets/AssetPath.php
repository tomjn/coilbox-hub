<?php

namespace App\Domain\Assets;

/**
 * Where an asset's bytes sit inside a tier, and which MIME types the hub takes
 * at all, `lib/assets/path.ts` in the Next app.
 *
 * The path is derived here and never taken from a caller, because promotion
 * writes it into a public git repository. The leaf is the hash of the encoded
 * bytes, so the same bytes always name the same key. Unit names, map names and
 * faction keys stay out of the path: a map name is free text, and two units in
 * one game may share one picture.
 */
final class AssetPath
{
    /** The MIME types an upload may declare, and the extension each is stored under. */
    public const MIME_EXTENSIONS = [
        'image/webp' => 'webp',
        'image/png' => 'png',
    ];

    /** Alphanumerics, dots, dashes and underscores, never a leading dot, so traversal cannot be spelled. */
    private const SAFE_SEGMENT = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/D';

    public static function isAssetMime(string $value): bool
    {
        return array_key_exists($value, self::MIME_EXTENSIONS);
    }

    /**
     * The tier relative path for one asset, or null when some part of the
     * identity cannot be a path segment. Null rather than a sanitised fallback,
     * which could map two identities onto one path.
     *
     * @param  array<string, string>  $identity  See {@see AssetResolver}.
     */
    public static function objectPath(array $identity, string $hash, string $mime): ?string
    {
        if (! self::isAssetMime($mime)) {
            return null;
        }
        if (! self::safe($hash)) {
            return null;
        }

        // `render:270` becomes `render/270`, so the colon never reaches a key.
        $variant = explode(':', $identity['variant']);
        foreach ($variant as $part) {
            if (! self::safe($part)) {
                return null;
            }
        }

        $leaf = $hash.'.'.self::MIME_EXTENSIONS[$mime];

        if ($identity['keyedOn'] === 'map') {
            return implode('/', ['maps', ...$variant, $leaf]);
        }

        if (! self::safe($identity['game'])) {
            return null;
        }

        $root = $identity['keyedOn'] === 'faction' ? 'factions' : 'units';

        return implode('/', [$root, $identity['game'], ...$variant, $leaf]);
    }

    private static function safe(string $segment): bool
    {
        return preg_match(self::SAFE_SEGMENT, $segment) === 1;
    }
}
