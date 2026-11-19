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
     * Staged objects nothing claims: no picture still holding its bytes, no
     * promoted picture still waiting for its staged copy to go, and no game's
     * logo or banner in the bucket. Games are read through the client, from
     * whichever database holds them.
     *
     * @return list<array{object_path: string, bytes: int, created_at: string}>
     */
    private static function unclaimed(int $max): array
    {
        $gameArt = [];
        $games = Supabase::admin()->from('game')->select('logo_path,logo_staged_tier,banner_path,banner_staged_tier')
            ->or('logo_staged_tier.eq.bucket,banner_staged_tier.eq.bucket')->get();
        foreach ($games as $game) {
            foreach (['logo', 'banner'] as $kind) {
                if (($game[$kind.'_staged_tier'] ?? null) === 'bucket' && ($game[$kind.'_path'] ?? null) !== null) {
                    $gameArt[] = $game[$kind.'_path'];
                }
            }
        }
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
