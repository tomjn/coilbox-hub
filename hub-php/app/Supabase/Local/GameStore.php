<?php

namespace App\Supabase\Local;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Which database holds the game catalogue: `supabase` until the copy, `frozen`
 * while `php artisan games:copy` runs, then `mariadb`. The state is a row in
 * `data_move`, so the copy command switches every server process at once and
 * no config reload is needed.
 *
 * `HUB_GAMES_STORE` fixes the state instead, for tests and local runs.
 */
final class GameStore
{
    public const AREA = 'games';

    public const SUPABASE = 'supabase';

    public const FROZEN = 'frozen';

    public const MARIADB = 'mariadb';

    public const STATES = [self::SUPABASE, self::FROZEN, self::MARIADB];

    /** Read once per request, for reads. */
    public static function state(): string
    {
        return once(fn () => self::fresh());
    }

    public static function local(): bool
    {
        return self::state() === self::MARIADB;
    }

    /**
     * Asked again before every write, so a request that started before the
     * copy froze the catalogue cannot write to Supabase after it.
     */
    public static function fresh(): string
    {
        $fixed = config('hub.games_store');
        if (is_string($fixed) && $fixed !== '') {
            return $fixed;
        }
        try {
            $state = DB::table('data_move')->where('area', self::AREA)->value('state');
        } catch (QueryException $e) {
            // Answered as a failed Supabase read, which every caller handles.
            throw new LocalException('Could not tell which database holds the games: '.$e->getMessage(), 503, null);
        }

        return is_string($state) ? $state : self::SUPABASE;
    }

    /** Where a write goes now, refusing while the copy runs. */
    public static function writeTarget(): string
    {
        $state = self::fresh();
        if ($state === self::FROZEN) {
            throw new LocalException('The game catalogue is being moved. Try again in a minute.', 503, 'HUB_FROZEN');
        }

        return $state;
    }

    public static function set(string $state): void
    {
        if (! in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException('Unknown state '.$state);
        }
        DB::table('data_move')->updateOrInsert(['area' => self::AREA], ['state' => $state, 'changed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
    }
}
