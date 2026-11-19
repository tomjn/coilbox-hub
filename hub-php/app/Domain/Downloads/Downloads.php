<?php

namespace App\Domain\Downloads;

use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use App\Support\HubCache;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

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

    /**
     * An IPv6 visitor holds a whole /64, so the key uses the network, not the
     * address. IPv4 stays whole.
     */
    public static function network(string $address): string
    {
        return IpUtils::anonymize($address, 0, 8);
    }

    /** True when the target exists. A repeat the same day is also true. */
    public static function record(string $kind, string $target, Request $request): bool
    {
        try {
            // Admin only, so a caller with the public key cannot skip the dedupe.
            $counted = Supabase::admin()->rpc('record_download', [
                'p_kind' => $kind,
                'p_target' => $target,
                'p_seen_key' => self::seenKey($kind, $target, self::network((string) $request->ip()), now()->toDateString()),
            ]);
        } catch (SupabaseException) {
            return false;
        }
        if ($counted !== true) {
            return false;
        }

        // Only the item page prints its count and is refreshed. Map and game
        // pages are left cached, their counts are rough and read separately.
        if ($kind === 'item') {
            HubCache::invalidate([HubCache::item($target)]);
        }

        return true;
    }

    /**
     * What a map or game page prints, or null when there is nothing to print:
     * no downloads yet, or the read failed. Held on its own short timer
     * outside the page cache, so a ping never has to refresh a page.
     *
     * @param  'map'|'game'  $kind
     * @return array{total: int, last30: int}|null
     */
    public static function counts(string $kind, ?string $targetId): ?array
    {
        if ($targetId === null) {
            return null;
        }
        try {
            $row = HubCache::remember("downloads:$kind:$targetId", [HubCache::DOWNLOADS], HubCache::DOWNLOAD_COUNTS, function () use ($kind, $targetId) {
                $rows = Supabase::anon()->from($kind.'_download_count')->select('downloads_total,downloads_30d')->eq('target_id', $targetId)->limit(1)->get();

                return $rows[0] ?? null;
            });
        } catch (SupabaseException) {
            return null;
        }
        $total = (int) ($row['downloads_total'] ?? 0);

        return $total > 0 ? ['total' => $total, 'last30' => (int) ($row['downloads_30d'] ?? 0)] : null;
    }
}
