<?php

namespace App\Supabase\Local;

/**
 * Which database holds published items, reports, profiles and moderator
 * capabilities (#601). `php artisan items:copy` switches it.
 * `HUB_ITEMS_STORE` fixes it, for tests and local runs.
 */
final class ItemStore extends DataMove
{
    public const AREA = 'items';

    protected static function area(): string
    {
        return self::AREA;
    }

    protected static function configKey(): string
    {
        return 'hub.items_store';
    }

    protected static function frozenMessage(): string
    {
        return 'Published items and accounts are being moved. Try again in a minute.';
    }

    public static function state(): string
    {
        return once(fn () => self::fresh());
    }
}
