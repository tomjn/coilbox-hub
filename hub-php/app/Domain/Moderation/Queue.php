<?php

namespace App\Domain\Moderation;

use App\Domain\Assets\AssetResolver;
use App\Domain\Assets\Staging;
use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * The picture moderation queue, `lib/assets/queue.ts` (issue #114).
 *
 * Reads use the secret key, because `asset_read_approved` shows browser roles
 * approved rows only. They never select `path`: a pending row's path names an
 * object nobody has reviewed, so it stays on the server and the browser gets
 * `/moderation/assets/<id>` instead, which checks `is_moderator()` per request.
 *
 * The three writes go the other way, through the moderator's own session.
 * `public.asset_event` records `auth.uid()`, which is null on a secret key
 * write, so a write as `service_role` would log the decision and not the
 * decider. The database functions ask `is_moderator()` themselves.
 */
final class Queue
{
    /**
     * How many pictures one contact sheet shows, and the most one submission
     * may act on. A normal day's uploads in one pass.
     */
    public const PAGE_SIZE = 240;

    /** Deliberately not `path`. */
    private const TILE_COLUMNS = 'id, game, unit_name, map_name, faction_key, variant, width, height, bytes, origin, source_archive, seen_at';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    /**
     * A map is not scoped to a game, so its caption names none. A unit is, and
     * unit names repeat across games, so its caption does. A faction logo is
     * captioned by its faction key.
     *
     * @param  array<string, mixed>  $row
     * @return array{name: string, detail: string}
     */
    public static function caption(array $row): array
    {
        if (($row['map_name'] ?? null) !== null) {
            return ['name' => (string) $row['map_name'], 'detail' => (string) $row['variant']];
        }

        return [
            'name' => (string) ($row['faction_key'] ?? $row['unit_name'] ?? ''),
            'detail' => ($row['game'] ?? '').' '.$row['variant'],
        ];
    }

    /**
     * The oldest pending pictures, oldest first, so nothing is pushed off the
     * end for ever. `total` is everything pending, which can be more than a page.
     *
     * @return array{waiting: list<array<string, mixed>>, total: int}
     */
    public static function fetch(Client $admin, int $limit = self::PAGE_SIZE): array
    {
        try {
            $result = $admin->from('asset')->select(self::TILE_COLUMNS, 'exact')
                ->eq('moderation', 'pending')->order('seen_at')->limit($limit)->getWithCount();
        } catch (SupabaseException) {
            $result = ['rows' => [], 'count' => null];
        }
        $rows = $result['rows'];
        $flagged = self::sourceConflicts($admin, array_column($rows, 'id'));

        return [
            'waiting' => array_map(fn (array $row) => ['id' => $row['id']] + self::caption($row) + [
                'width' => $row['width'],
                'height' => $row['height'],
                'bytes' => $row['bytes'],
                'origin' => $row['origin'],
                'sourceArchive' => $row['source_archive'],
                'sourceConflict' => isset($flagged[$row['id']]),
            ], $rows),
            'total' => $result['count'] ?? count($rows),
        ];
    }

    /**
     * Which of these assets somebody has reported different source bytes for,
     * `fetchSourceConflicts` in `lib/assets/sourceConflict.ts`. A second read
     * rather than an embed, and a failure is no marks rather than no queue.
     *
     * @param  list<string>  $assetIds
     * @return array<string, true>
     */
    public static function sourceConflicts(Client $admin, array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }
        try {
            $rows = $admin->from('asset_source_conflict')->select('asset_id')->in('asset_id', $assetIds)->get();
        } catch (SupabaseException) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['asset_id']] = true;
        }

        return $out;
    }

    /**
     * One row's own bytes, in any moderation state, or null when there is no
     * row or a bucket row has no object. Not the public resolver, which refuses
     * unapproved rows and draws placeholders. Any other storage failure throws.
     *
     * @return array{source: 'url', url: string, mime: string}|array{source: 'bytes', bytes: string, mime: string}|null
     */
    public static function fetchObject(Client $admin, string $id): ?array
    {
        $row = $admin->from('asset')->select('path, tier, mime')->eq('id', $id)->maybeSingle();
        if ($row === null) {
            return null;
        }
        if ($row['tier'] === 'bucket') {
            $bytes = Staging::download($admin, (string) $row['path']);

            return $bytes === null ? null : ['source' => 'bytes', 'bytes' => $bytes, 'mime' => (string) $row['mime']];
        }

        return ['source' => 'url', 'url' => AssetResolver::assetTierUrl((string) $row['tier'], (string) $row['path']), 'mime' => (string) $row['mime']];
    }

    /** Whether a value off a form or query string may be used as an id at all. */
    public static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::UUID, $value) === 1;
    }

    /**
     * The ids a form may act on: uuids only, once each, at most a page.
     *
     * @param  array<mixed>  $values
     * @return list<string>
     */
    public static function pictureIds(array $values): array
    {
        return array_slice(array_values(array_unique(array_filter($values, self::isUuid(...)))), 0, self::PAGE_SIZE);
    }

    /**
     * Approve pictures and return how many moved. The function only moves rows
     * still pending, so a stale tab cannot undo another moderator's rejection.
     *
     * @param  list<string>  $ids
     */
    public static function approve(Client $session, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        try {
            $moved = $session->rpc('approve_assets', ['ids' => $ids]);
        } catch (SupabaseException) {
            return 0;
        }

        return is_int($moved) ? $moved : 0;
    }

    /** Reject one picture as `editorial` or `safety`. Nothing deletes the row. */
    public static function reject(Client $session, string $id, string $kind): bool
    {
        try {
            return $session->rpc('reject_asset', ['asset_id' => $id, 'kind' => $kind]) === true;
        } catch (SupabaseException) {
            return false;
        }
    }

    /** Put an editorial rejection back. The database refuses a safety one. */
    public static function return(Client $session, string $id): bool
    {
        try {
            return $session->rpc('return_asset', ['asset_id' => $id]) === true;
        } catch (SupabaseException) {
            return false;
        }
    }
}
