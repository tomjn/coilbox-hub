<?php

namespace App\Supabase\Local;

/**
 * Where Coilbox signs in (#602, part 3c): `supabase` until the switch, then
 * `mariadb`, when the hub runs `/api/v1/auth/authorize` and issues its own
 * tokens. `php artisan tokens:store` switches it, and `HUB_TOKENS_STORE`
 * fixes it for tests and local runs.
 *
 * Tokens the hub has issued keep working in either state, and so do
 * Supabase's until they expire, so switching either way signs nobody out.
 */
final class TokenStore extends DataMove
{
    public const AREA = 'tokens';

    protected static function area(): string
    {
        return self::AREA;
    }

    protected static function configKey(): string
    {
        return 'hub.tokens_store';
    }

    protected static function frozenMessage(): string
    {
        return 'Sign in is being moved. Try again in a minute.';
    }

    public static function state(): string
    {
        return once(fn () => self::fresh());
    }

    /** Why switching does nothing while `HUB_TOKENS_STORE` fixes the state, or null when `data_move` decides. */
    public static function fixedMessage(): ?string
    {
        $fixed = self::fixed();

        return $fixed === null
            ? null
            : "HUB_TOKENS_STORE is set to {$fixed}, which overrides data_move, so this would change nothing. Remove it from .env, then run php artisan config:cache.";
    }
}
