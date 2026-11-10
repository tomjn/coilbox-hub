<?php

namespace App\Domain\Assets;

use App\Supabase\Client;
use App\Supabase\SupabaseException;
use App\Support\Site;

/**
 * How an approved picture in the private staging bucket reaches a browser
 * before promotion, `lib/assets/bucket.ts` in the Next app.
 *
 * The bucket has no public URL, so the hub serves the bytes at
 * `/assets/staged/<path>`. A route rather than a signed URL, because a signed
 * URL held in a cached page breaks when it expires. The path is a hash of the
 * bytes, so the response is cached as immutable.
 *
 * Only an approved row is served, read with the anonymous client so row level
 * security is a second layer. Pending, rejected and missing all look the same.
 * A row promoted since is answered with a redirect to the durable tier.
 */
final class Bucket
{
    public const ROUTE_PREFIX = '/assets/staged/';

    /** Absolute, because the desktop app has no page to resolve a relative URL against. */
    public static function tierUrl(string $path): string
    {
        return Site::url().self::ROUTE_PREFIX.ltrim($path, '/');
    }

    /**
     * The bytes at a bucket path when an approved bucket row names it, as
     * `['bytes' => string, 'mime' => string]`. `['promoted' => url]` when an
     * approved row there has moved to the durable tier. Null otherwise, and null
     * too when the row exists but the object does not.
     *
     * Throws {@see SupabaseException} on any other failure.
     *
     * @return array{bytes: string, mime: string}|array{promoted: string}|null
     */
    public static function fetchApprovedStagedPicture(Client $anon, Client $admin, string $path): ?array
    {
        // `static` sorts after `bucket`, so descending puts a promoted row first.
        $rows = $anon->from('asset')
            ->select('mime, tier')
            ->eq('path', $path)
            ->in('tier', ['bucket', 'static'])
            ->eq('moderation', 'approved')
            ->order('tier', false)
            ->limit(1)
            ->get();

        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }
        if ($row['tier'] === 'static') {
            return ['promoted' => Cdn::staticTierUrl($path)];
        }

        $bytes = Staging::download($admin, $path);

        return $bytes === null ? null : ['bytes' => $bytes, 'mime' => (string) $row['mime']];
    }
}
