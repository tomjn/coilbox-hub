<?php

namespace App\Supabase\Local;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Which database holds one area: `supabase` until its copy, `frozen` while the
 * copy runs, then `mariadb`. The state is a row in `data_move`, so a copy
 * command switches every server process at once and no config reload is
 * needed. A config value fixes the state instead, for tests and local runs.
 */
abstract class DataMove
{
    public const SUPABASE = 'supabase';

    public const FROZEN = 'frozen';

    public const MARIADB = 'mariadb';

    public const STATES = [self::SUPABASE, self::FROZEN, self::MARIADB];

    /** The `data_move` row's area. */
    abstract protected static function area(): string;

    /** The config key that fixes the state. */
    abstract protected static function configKey(): string;

    /** What a write refused during the copy says. */
    abstract protected static function frozenMessage(): string;

    /** Read once per request, for reads. */
    abstract public static function state(): string;

    public static function local(): bool
    {
        return static::state() === self::MARIADB;
    }

    /**
     * Asked again before every write, so a request that started before a copy
     * froze the area cannot write to Supabase after it.
     */
    public static function fresh(): string
    {
        $fixed = config(static::configKey());
        if (is_string($fixed) && $fixed !== '') {
            return $fixed;
        }
        try {
            $state = DB::table('data_move')->where('area', static::area())->value('state');
        } catch (QueryException $e) {
            // Answered as a failed Supabase read, which every caller handles.
            throw new LocalException('Could not tell which database holds the '.static::area().': '.$e->getMessage(), 503, null);
        }

        return is_string($state) ? $state : self::SUPABASE;
    }

    /** Where a write goes now, refusing while the copy runs. */
    public static function writeTarget(): string
    {
        $state = static::fresh();
        if ($state === self::FROZEN) {
            throw new LocalException(static::frozenMessage(), 503, 'HUB_FROZEN');
        }

        return $state;
    }

    public static function set(string $state): void
    {
        if (! in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException('Unknown state '.$state);
        }
        DB::table('data_move')->updateOrInsert(['area' => static::area()], ['state' => $state, 'changed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
    }
}
