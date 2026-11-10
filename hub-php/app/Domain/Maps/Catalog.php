<?php

namespace App\Domain\Maps;

/**
 * The request caps, read out of the vendored map catalog rather than written
 * out here, `lib/maps/catalog.ts`. The client batches on the same file, so the
 * number it splits on and the number the hub enforces are one number.
 */
final class Catalog
{
    /** @var array{haveKeys: int, submitMaps: int, submitBytes: int, lookupNames: int}|null */
    private static ?array $caps = null;

    /** @return array{haveKeys: int, submitMaps: int, submitBytes: int, lookupNames: int} */
    public static function caps(): array
    {
        if (self::$caps === null) {
            $catalog = json_decode((string) file_get_contents(resource_path('vendor/map-catalog.json')), true, 512, JSON_THROW_ON_ERROR);
            self::$caps = $catalog['caps'];
        }

        return self::$caps;
    }
}
