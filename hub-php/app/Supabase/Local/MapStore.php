<?php

namespace App\Supabase\Local;

/**
 * Which database holds maps, map authors and map packs (#599).
 * `php artisan maps:copy` switches it. `HUB_MAPS_STORE` fixes it, for tests
 * and local runs.
 */
final class MapStore extends DataMove
{
    public const AREA = 'maps';

    protected static function area(): string
    {
        return self::AREA;
    }

    protected static function configKey(): string
    {
        return 'hub.maps_store';
    }

    protected static function frozenMessage(): string
    {
        return 'Maps are being moved. Try again in a minute.';
    }

    public static function state(): string
    {
        return once(fn () => self::fresh());
    }
}
