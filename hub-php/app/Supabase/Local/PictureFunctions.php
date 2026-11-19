<?php

namespace App\Supabase\Local;

use App\Supabase\Client;
use App\Supabase\Supabase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The picture functions the hub calls, done in PHP on the local tables
 * (#598), as production's schema defines them. Each keeps its grants, its
 * moderator check and its answers. Updates to `asset` go through
 * {@see Writes}, so the triggers run as they did in Postgres.
 *
 * `has_capability` and `is_moderator` stay in Supabase. They read
 * `user_capability` and the sign in factors, which every area uses and which
 * have not moved.
 */
final class PictureFunctions
{
    /** Function => roles granted execute. */
    private const GRANTS = [
        'approve_assets' => ['authenticated'],
        'reject_asset' => ['authenticated'],
        'return_asset' => ['authenticated'],
        'acknowledge_source_conflict' => ['authenticated'],
        'approved_source_conflicts' => ['authenticated'],
        'account_asset_bytes' => ['service_role'],
        'asset_storage_usage' => ['service_role'],
        'staged_pictures_bucket_bytes' => ['service_role'],
        'unclaimed_staged_objects' => ['service_role'],
        'promote_assets' => ['service_role'],
        'clear_promoted_blob_paths' => ['service_role'],
        'record_asset_withdrawn' => ['service_role'],
        'reserve_staged_deletions' => ['service_role'],
        'release_staged_deletions' => ['service_role'],
    ];

    private const READS = [
        'approved_source_conflicts', 'account_asset_bytes', 'asset_storage_usage', 'staged_pictures_bucket_bytes',
        'unclaimed_staged_objects',
    ];

    /** `asset_holds_staged_bytes`: an editorial rejection lets go of its staged bytes after this many days. */
    private const EDITORIAL_HOLD_DAYS = 14;

    public static function handles(string $function): bool
    {
        return isset(self::GRANTS[$function]);
    }

    public static function writes(string $function): bool
    {
        return ! in_array($function, self::READS, true);
    }

    /** @param array<string, mixed> $args */
    public static function call(Client $client, string $function, array $args): mixed
    {
        $caller = new Caller($client);
        if (! in_array($caller->role(), self::GRANTS[$function], true)) {
            throw new LocalException('permission denied for function '.$function, 401, '42501');
        }

        return match ($function) {
            'approve_assets' => self::approve($caller, self::ids($args['ids'] ?? [])),
            'reject_asset' => self::reject($caller, (string) ($args['asset_id'] ?? ''), $args['kind'] ?? null),
            'return_asset' => self::return($caller, (string) ($args['asset_id'] ?? '')),
            'acknowledge_source_conflict' => self::acknowledge($caller, (int) ($args['p_conflict_id'] ?? 0)),
            'approved_source_conflicts' => self::approvedConflicts($caller, $args['p_limit'] ?? null),
            'account_asset_bytes' => (int) DB::table('asset')->where('uploaded_by', (string) ($args['account'] ?? ''))
                ->where('tier', 'bucket')->sum('bytes'),
            'asset_storage_usage' => array_map(fn ($row) => [
                'tier' => $row->tier, 'variant' => $row->variant, 'objects' => (int) $row->objects, 'bytes' => (int) $row->bytes,
            ], DB::table('asset')->select('tier', 'variant')->selectRaw('count(*) as objects, coalesce(sum(bytes), 0) as bytes')
                ->groupBy('tier', 'variant')->orderBy('tier')->orderBy('variant')->get()->all()),
            'staged_pictures_bucket_bytes' => (int) DB::table('staged_object')->sum('bytes'),
            'unclaimed_staged_objects' => self::unclaimed((int) ($args['max_objects'] ?? 0)),
            'promote_assets' => self::promote($caller, self::ids($args['ids'] ?? []), self::ids($args['paths'] ?? [])),
            'clear_promoted_blob_paths' => DB::table('asset')->whereIn('id', self::ids($args['ids'] ?? []))
                ->whereNotNull('blob_path')->update(['blob_path' => null, 'blob_path_tier' => null]),
            'record_asset_withdrawn' => DB::table('asset_withdrawal')->whereIn('asset_id', self::ids($args['ids'] ?? []))
                ->whereNull('withdrawn_at')->update(['withdrawn_at' => Values::now()]),
            'reserve_staged_deletions' => self::reserve(self::ids($args['object_paths'] ?? []), (bool) ($args['queued_is_claimed'] ?? false)),
            'release_staged_deletions' => DB::table('staged_object_deletion')->whereIn('path', self::ids($args['object_paths'] ?? []))->delete(),
        };
    }

    /** Updated before this, an editorial rejection no longer holds its staged bytes. */
    private static function holdEnds(): string
    {
        return CarbonImmutable::now('UTC')->subDays(self::EDITORIAL_HOLD_DAYS)->format('Y-m-d H:i:s.u');
    }

    /** @return list<string> */
    private static function ids(mixed $ids): array
    {
        return array_values(array_map('strval', is_array($ids) ? $ids : []));
    }

    private static function requireModerator(Caller $caller, string $message): void
    {
        if (! $caller->isModerator()) {
            throw new LocalException($message, 403, '42501');
        }
    }

    /** @param list<string> $ids */
    private static function approve(Caller $caller, array $ids): int
    {
        self::requireModerator($caller, 'approving pictures needs can_moderate');

        return self::update($caller, fn (Builder $q) => $q->whereIn('id', $ids)->where('moderation', 'pending'),
            ['moderation' => 'approved', 'approval_source' => 'moderator']);
    }

    private static function reject(Caller $caller, string $id, mixed $kind): bool
    {
        self::requireModerator($caller, 'rejecting a picture needs can_moderate');
        if (! in_array($kind, ['safety', 'editorial'], true)) {
            throw new LocalException('a rejection is either safety or editorial, not '.(is_string($kind) ? $kind : ''), 400, '23514');
        }

        return self::update($caller, fn (Builder $q) => $q->where('id', $id)->where('moderation', '<>', 'rejected'),
            ['moderation' => 'rejected', 'rejection_kind' => $kind]) > 0;
    }

    private static function return(Caller $caller, string $id): bool
    {
        self::requireModerator($caller, 'returning a picture to the queue needs can_moderate');
        $releasedBefore = self::holdEnds();

        return self::update($caller, fn (Builder $q) => $q->where('id', $id)->where('moderation', 'rejected')
            ->where('rejection_kind', '<>', 'safety')
            ->where(fn (Builder $held) => $held->where('tier', 'static')
                // asset_holds_staged_bytes: not an editorial rejection older than the hold.
                ->orWhere('rejection_kind', '<>', 'editorial')
                ->orWhere('updated_at', '>=', $releasedBefore)),
            ['moderation' => 'pending', 'rejection_kind' => null, 'approval_source' => null]) > 0;
    }

    /**
     * One `update public.asset ... where ...`, run as the function's owner with
     * the caller's id for the triggers. Answers the rows changed.
     *
     * @param  \Closure(Builder): mixed  $where
     * @param  array<string, mixed>  $values
     */
    private static function update(Caller $caller, \Closure $where, array $values): int
    {
        $definer = $caller->definer();

        return DB::transaction(function () use ($definer, $where, $values) {
            $query = DB::table('asset');
            $where($query);
            $targets = array_map(fn ($row) => (array) $row, $query->orderBy('id')->lockForUpdate()->get()->all());

            return count(Writes::update($definer, 'asset', $targets, $values, Values::now()));
        });
    }

    private static function acknowledge(Caller $caller, int $conflictId): bool
    {
        self::requireModerator($caller, 'clearing a disagreement needs can_moderate');
        $userId = $caller->userId();

        return DB::transaction(function () use ($conflictId, $userId) {
            $conflict = DB::table('asset_source_conflict as c')->join('asset as a', 'a.id', '=', 'c.asset_id')
                ->where('c.id', $conflictId)->whereNull('c.acknowledged_at')
                ->select('a.id as asset_id', 'a.uploaded_by')->lockForUpdate()->first();
            if ($conflict === null) {
                return false;
            }
            $now = Values::now();
            DB::table('asset_source_conflict')->where('id', $conflictId)->update(['acknowledged_at' => $now, 'acknowledged_by' => $userId]);
            DB::table('asset_event')->insert([
                'asset_id' => $conflict->asset_id, 'action' => 'conflict_acknowledged', 'actor' => $userId,
                'uploader' => $conflict->uploaded_by, 'at' => $now,
            ]);

            return true;
        });
    }

    /** @return list<array<string, mixed>> */
    private static function approvedConflicts(Caller $caller, mixed $limit): array
    {
        self::requireModerator($caller, 'the list is for moderators');
        $open = fn () => DB::table('asset_source_conflict as c')->join('asset as a', 'a.id', '=', 'c.asset_id')
            ->where('a.moderation', 'approved')->whereNull('c.acknowledged_at');
        $limit = max((int) ($limit ?? 0), 0);
        $rows = $limit === 0 ? [] : $open()
            ->select('c.id', 'c.asset_id', 'a.game', 'a.unit_name', 'a.map_name', 'a.faction_key', 'a.variant',
                'c.source_archive', 'c.held_source_hash', 'c.reported_source_hash', 'c.reported_by', 'a.uploaded_by', 'c.at')
            ->orderBy('c.at')->orderBy('c.id')->limit($limit)->get()->all();
        $total = $rows === [] ? 0 : $open()->count();

        return array_map(fn ($row) => [
            'conflict_id' => (int) $row->id,
            'asset_id' => $row->asset_id,
            'game' => $row->game,
            'unit_name' => $row->unit_name,
            'map_name' => $row->map_name,
            'faction_key' => $row->faction_key,
            'variant' => $row->variant,
            'source_archive' => $row->source_archive,
            'held_source_hash' => $row->held_source_hash,
            'reported_source_hash' => $row->reported_source_hash,
            'reported_by' => $row->reported_by,
            'uploaded_by' => $row->uploaded_by,
            'at' => Values::timestamp((string) $row->at),
            'total' => $total,
        ], $rows);
    }

    /**
     * `promote_assets`: each approved picture still on the staging tier moves to
     * its durable path, keeping the staging path in `blob_path` until the job
     * has deleted the object. Answers with the rows that moved.
     *
     * @param  list<string>  $ids
     * @param  list<string>  $paths
     * @return list<array{id: string, blob_path: string, blob_path_tier: string}>
     */
    private static function promote(Caller $caller, array $ids, array $paths): array
    {
        if (count($ids) !== count($paths)) {
            throw new LocalException('promote_assets wants one path per id', 400, '22023');
        }
        $wanted = array_combine($ids, $paths);
        $now = Values::now();

        return DB::transaction(function () use ($caller, $wanted, $now) {
            $targets = DB::table('asset')->whereIn('id', array_keys($wanted))->where('tier', '<>', 'static')
                ->where('moderation', 'approved')->whereNull('blob_path')->whereNull('bytes_missing_at')
                ->orderBy('id')->lockForUpdate()->get()->all();
            $moved = [];
            foreach ($targets as $target) {
                $target = (array) $target;
                Writes::update($caller, 'asset', [$target], [
                    'path' => $wanted[$target['id']], 'tier' => 'static', 'promoted_at' => $now,
                    'blob_path' => $target['path'], 'blob_path_tier' => $target['tier'],
                ], $now);
                $moved[] = ['id' => $target['id'], 'blob_path' => $target['path'], 'blob_path_tier' => $target['tier']];
            }

            return $moved;
        });
    }

    /**
     * `reserve_staged_deletions`: reserves the paths nothing claims and answers
     * with them, which is the caller's delete list. A path already reserved
     * comes back again, so a run can finish what a dead run started.
     *
     * The reservation row is written first and the claims are read after it
     * with locking reads. An upload claiming the path locks the same
     * reservation row ({@see AssetTriggers::before()}), so whichever commits
     * first wins, as with Postgres's advisory lock.
     *
     * @param  list<string>  $paths
     * @return list<array{object_path: string}>
     */
    private static function reserve(array $paths, bool $queuedIsClaimed): array
    {
        $paths = array_values(array_unique($paths));
        sort($paths);
        if ($paths === []) {
            return [];
        }
        $gameArt = self::stagedGameArt();
        $releasedBefore = self::holdEnds();

        return DB::transaction(function () use ($paths, $queuedIsClaimed, $gameArt, $releasedBefore) {
            $reserved = [];
            foreach ($paths as $path) {
                $fresh = DB::table('staged_object_deletion')->insertOrIgnore(['path' => $path, 'reserved_at' => Values::now()]) === 1;
                $claimed = in_array($path, $gameArt, true)
                    || DB::table('asset')->where('path', $path)->where('tier', '<>', 'static')
                        ->where(fn (Builder $holds) => $holds->where('moderation', '<>', 'rejected')
                            ->orWhere('rejection_kind', '<>', 'editorial')
                            ->orWhere('updated_at', '>=', $releasedBefore))
                        ->lockForUpdate()->exists()
                    || ($queuedIsClaimed && DB::table('asset')->where('blob_path', $path)->lockForUpdate()->exists());
                if ($claimed) {
                    if ($fresh) {
                        DB::table('staged_object_deletion')->where('path', $path)->delete();
                    }

                    continue;
                }
                $reserved[] = ['object_path' => $path];
            }

            return $reserved;
        }, 3);
    }

    /**
     * The paths a game's logo, banner or card holds in the bucket. Read
     * through the client, from whichever database holds games. Postgres's
     * functions left the card out, which let the sweep delete a staged card.
     *
     * @return list<string>
     */
    private static function stagedGameArt(): array
    {
        $kinds = ['logo', 'banner', 'card'];
        $columns = implode(',', array_merge(...array_map(fn ($kind) => [$kind.'_path', $kind.'_staged_tier'], $kinds)));
        $games = Supabase::admin()->from('game')->select($columns)
            ->or(implode(',', array_map(fn ($kind) => $kind.'_staged_tier.eq.bucket', $kinds)))->get();
        $paths = [];
        foreach ($games as $game) {
            foreach ($kinds as $kind) {
                if (($game[$kind.'_staged_tier'] ?? null) === 'bucket' && ($game[$kind.'_path'] ?? null) !== null) {
                    $paths[] = $game[$kind.'_path'];
                }
            }
        }

        return $paths;
    }

    /**
     * Staged objects nothing claims: no picture still holding its bytes, no
     * promoted picture still waiting for its staged copy to go, and no game's
     * logo, banner or card in the bucket.
     *
     * @return list<array{object_path: string, bytes: int, created_at: string}>
     */
    private static function unclaimed(int $max): array
    {
        $gameArt = self::stagedGameArt();
        $releasedBefore = self::holdEnds();
        $query = DB::table('staged_object as o')
            ->whereNotExists(fn (Builder $held) => $held->from('asset as a')->whereColumn('a.path', 'o.path')->where('a.tier', '<>', 'static')
                ->where(fn (Builder $holds) => $holds->where('a.moderation', '<>', 'rejected')
                    ->orWhere('a.rejection_kind', '<>', 'editorial')
                    ->orWhere('a.updated_at', '>=', $releasedBefore)))
            ->whereNotExists(fn (Builder $queued) => $queued->from('asset as b')->whereColumn('b.blob_path', 'o.path'));
        if ($gameArt !== []) {
            $query->whereNotIn('o.path', $gameArt);
        }

        return array_map(fn ($row) => [
            'object_path' => $row->path,
            'bytes' => (int) $row->bytes,
            'created_at' => Values::timestamp((string) $row->created_at),
        ], $query->orderBy('o.created_at')->orderBy('o.path')->limit(max($max, 0))->get()->all());
    }
}
