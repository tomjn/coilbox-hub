<?php

namespace App\Supabase\Local;

/**
 * Which database holds the hub mirrors, their archives and the catalogue (#600).
 * `php artisan mirrors:copy` switches it. `HUB_MIRRORS_STORE` fixes it, for tests
 * and local runs.
 */
final class MirrorStore extends DataMove
{
    public const AREA = 'mirrors';

    protected static function area(): string
    {
        return self::AREA;
    }

    protected static function configKey(): string
    {
        return 'hub.mirrors_store';
    }

    protected static function frozenMessage(): string
    {
        return 'The mirrors are being moved. Try again in a minute.';
    }

    public static function state(): string
    {
        return once(fn () => self::fresh());
    }
}
