<?php

namespace App\Domain\Downloads;

use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use App\Support\HubCache;
use Illuminate\Http\Request;

/**
 * Anonymous download counters. The database counts a download once per seen
 * key per day, so the key says who and what without storing an address.
 */
final class Downloads
{
    /**
     * Kind and target are part of the key because the seen table is keyed on
     * the day and this key alone.
     */
    public static function seenKey(string $kind, string $target, string $address, string $date): string
    {
        return hash_hmac('sha256', "$date|$address|$kind|$target", (string) config('app.key'));
    }

    /** True when the database counted this download. */
    public static function record(string $kind, string $target, Request $request): bool
    {
        try {
            $counted = Supabase::anon()->rpc('record_download', [
                'p_kind' => $kind,
                'p_target' => $target,
                'p_seen_key' => self::seenKey($kind, $target, (string) $request->ip(), now()->toDateString()),
            ]);
        } catch (SupabaseException) {
            return false;
        }
        if ($counted !== true) {
            return false;
        }

        // The map page tags are keyed by slug and only the name is known here,
        // so the whole maps tag goes. Games have no per page tag.
        HubCache::invalidate([match ($kind) {
            'item' => HubCache::item($target),
            'map' => HubCache::MAPS,
            default => HubCache::GAMES,
        }]);

        return true;
    }
}
