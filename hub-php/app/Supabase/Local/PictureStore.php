<?php

namespace App\Supabase\Local;

/**
 * Which database holds the picture records and which disk holds the staged
 * pictures (#598). `php artisan pictures:copy` switches it.
 * `HUB_PICTURES_STORE` fixes it, for tests and local runs.
 */
final class PictureStore extends DataMove
{
    public const AREA = 'pictures';

    protected static function area(): string
    {
        return self::AREA;
    }

    protected static function configKey(): string
    {
        return 'hub.pictures_store';
    }

    protected static function frozenMessage(): string
    {
        return 'Pictures are being moved. Try again in a minute.';
    }

    public static function state(): string
    {
        return once(fn () => self::fresh());
    }
}
