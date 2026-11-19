<?php

namespace App\Supabase\Local;

/**
 * Where the browser sign in runs (#602, part 3b): `supabase` until the switch,
 * `frozen` while `php artisan users:copy --switch` copies the accounts, then
 * `mariadb`, when the hub runs it with Socialite against the accounts in
 * `auth_user`. `HUB_SIGNIN_STORE` fixes it, for tests and local runs.
 */
final class SignInStore extends DataMove
{
    public const AREA = 'signin';

    /** The areas a session without a Supabase token needs in MariaDB, so row level security is the hub's. */
    public const NEEDS = [
        'games' => GameStore::class,
        'pictures' => PictureStore::class,
        'maps' => MapStore::class,
        'mirrors' => MirrorStore::class,
        'items' => ItemStore::class,
    ];

    protected static function area(): string
    {
        return self::AREA;
    }

    protected static function configKey(): string
    {
        return 'hub.signin_store';
    }

    protected static function frozenMessage(): string
    {
        return 'Accounts are being moved. Try signing in again in a minute.';
    }

    public static function state(): string
    {
        return once(fn () => self::fresh());
    }

    /** Why switching does nothing while `HUB_SIGNIN_STORE` fixes the state, or null when `data_move` decides. */
    public static function fixedMessage(): ?string
    {
        $fixed = self::fixed();

        return $fixed === null
            ? null
            : "HUB_SIGNIN_STORE is set to {$fixed}, which overrides data_move, so this would change nothing. Remove it from .env, then run php artisan config:cache.";
    }

    /** What a `*:store supabase` command says while sign in runs on the hub, or null when it may go ahead. */
    public static function blocksRollback(): ?string
    {
        return self::fresh() === self::SUPABASE
            ? null
            : 'Sign in runs on the hub, and its sessions carry no Supabase token. Run users:store supabase first.';
    }
}
