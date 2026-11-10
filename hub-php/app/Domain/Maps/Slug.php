<?php

namespace App\Domain\Maps;

use Normalizer;

/**
 * The name a map has in a URL, `lib/maps/slug.ts`. Computed by the hub from the
 * canonical map name and never sent by a client, because `public.map.slug` is
 * unique and a declared slug could take another map's URL.
 */
final class Slug
{
    /** The column holds 256 and the alternative adds nine, so the base stops here. */
    private const MAX_BASE = 240;

    /** Hex characters of the name's digest that tell two colliding names apart. */
    private const SUFFIX_LENGTH = 8;

    /** A name written entirely in punctuation still needs a slug the column accepts. */
    private const UNSLUGGABLE = 'map';

    /**
     * Lower case, Unicode letters and digits kept, everything else one hyphen.
     * Letters in any script stay, so a Cyrillic name keeps a readable slug.
     * NFC first, so two byte spellings of one accented name give one slug.
     */
    public static function for(string $mapName): string
    {
        $normal = Normalizer::normalize($mapName, Normalizer::FORM_C);
        $slug = mb_strtolower($normal === false ? $mapName : $normal, 'UTF-8');
        $slug = (string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug);
        $slug = (string) preg_replace('/^-+|-+$/', '', $slug);
        $slug = (string) preg_replace('/-+$/', '', Js::slice($slug, self::MAX_BASE));

        return $slug !== '' ? $slug : self::UNSLUGGABLE;
    }

    /**
     * The slug a map takes when another map already holds the first one. From
     * the name rather than the facts, so the URL does not move when facts improve.
     */
    public static function alternative(string $mapName): string
    {
        return self::for($mapName).'-'.substr(hash('sha256', $mapName), 0, self::SUFFIX_LENGTH);
    }
}
