<?php

namespace App\Domain\Downloads;

use App\Domain\Moderation\Queue;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Anonymous download counters, kept in MariaDB. A download counts once per
 * seen key per day, so the key says who and what without storing an address.
 *
 * `download_day` holds the counts. `download_seen` holds the keys that
 * already counted, pruned on every download, keeping yesterday as well so a
 * request just past midnight does not count twice.
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
        // A missing or malformed address must not break the download.
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return $address;
        }

        return IpUtils::anonymize($address, 0, 8);
    }

    /**
     * True when the target exists. A repeat the same day is also true, because
     * the caller cannot tell it from a first download and should not retry.
     */
    public static function record(string $kind, string $target, Request $request): bool
    {
        $targetId = self::targetId($kind, $target);
        if ($targetId === null) {
            return false;
        }
        $today = now()->toDateString();
        $key = self::seenKey($kind, $target, self::network((string) $request->ip()), $today);

        try {
            DB::transaction(function () use ($kind, $targetId, $today, $key) {
                DB::table('download_seen')->where('day', '<', now()->subDay()->toDateString())->delete();
                if (DB::table('download_seen')->insertOrIgnore(['day' => $today, 'seen_key' => $key]) === 0) {
                    return;
                }
                DB::table('download_day')->upsert(
                    [['kind' => $kind, 'target_id' => $targetId, 'day' => $today, 'count' => 1]],
                    ['kind', 'target_id', 'day'],
                    ['count' => DB::raw('download_day.count + 1')],
                );
            });
        } catch (QueryException $e) {
            Log::error('A download could not be counted', ['kind' => $kind, 'error' => $e->getMessage()]);

            return false;
        }

        return true;
    }

    /**
     * What a map or game page prints, or null when there is nothing to print:
     * no downloads yet, or the read failed.
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
            $row = DB::table('download_day')
                ->where('kind', $kind)->where('target_id', $targetId)
                ->selectRaw('coalesce(sum(count), 0) as total, coalesce(sum(case when day > ? then count else 0 end), 0) as last30', [self::since(30)])
                ->first();
        } catch (QueryException) {
            return null;
        }
        $total = (int) ($row->total ?? 0);

        return $total > 0 ? ['total' => $total, 'last30' => (int) $row->last30] : null;
    }

    /** Every download of one target, zero when the read fails. */
    public static function total(string $kind, string $targetId): int
    {
        try {
            return (int) DB::table('download_day')->where('kind', $kind)->where('target_id', $targetId)->sum('count');
        } catch (QueryException) {
            return 0;
        }
    }

    /**
     * Downloads per target over the last `$days` days, for the popular sorts.
     * Targets with none are left out.
     *
     * @return array<string, int> target id to count
     *
     * @throws QueryException when MariaDB cannot be read
     */
    public static function ranking(string $kind, int $days): array
    {
        return DB::table('download_day')
            ->where('kind', $kind)->where('day', '>', self::since($days))
            ->groupBy('target_id')
            ->selectRaw('target_id, sum(count) as downloads')
            ->pluck('downloads', 'target_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /** The day before the first day a window of `$days` days counts. */
    private static function since(int $days): string
    {
        return now()->subDays($days)->toDateString();
    }

    /**
     * The id of the map, visible game or live item a download names, or null
     * when there is none or Supabase could not say. Read as admin, because a
     * map behind the licence gate still counts.
     */
    private static function targetId(string $kind, string $target): ?string
    {
        if ($kind === 'item' && ! Queue::isUuid($target)) {
            return null;
        }
        try {
            $query = match ($kind) {
                'map' => Supabase::admin()->from('map')->select('id')->eq('map_name', $target),
                'game' => Supabase::admin()->from('game')->select('id')->eq('shortname', $target)->is('hidden_at', null),
                'item' => Supabase::admin()->from('item')->select('id')->eq('id', $target)->is('deleted_at', null),
            };
            $rows = $query->limit(1)->get();
        } catch (SupabaseException) {
            return null;
        }

        return isset($rows[0]['id']) ? (string) $rows[0]['id'] : null;
    }
}
