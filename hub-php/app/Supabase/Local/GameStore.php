<?php

namespace App\Supabase\Local;

/**
 * Which database holds the game catalogue (#597). `php artisan games:copy`
 * switches it. `HUB_GAMES_STORE` fixes it, for tests and local runs.
 */
final class GameStore extends DataMove
{
    public const AREA = 'games';

    protected static function area(): string
    {
        return self::AREA;
    }

    protected static function configKey(): string
    {
        return 'hub.games_store';
    }

    protected static function frozenMessage(): string
    {
        return 'The game catalogue is being moved. Try again in a minute.';
    }

    public static function state(): string
    {
        return once(fn () => self::fresh());
    }
}
