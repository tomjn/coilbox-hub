<?php

namespace App\Domain\Promotion;

use App\Domain\Moderation\Promotion;
use App\Supabase\Client;
use App\Supabase\Local\StagedObjects;
use DateTimeInterface;

/**
 * What the daily promotion job asks of the hub (#616), one method per
 * endpoint under `/api/v1/promotion`. The job in tomjn/coilbox-assets used to
 * ask Supabase these with the service role key. Each goes through the admin
 * client, so it reads whichever database holds pictures and games.
 *
 * The order of the run and why each step is safe are in
 * `lib/assets/promote.ts` and `lib/assets/orphan.ts`, which call these.
 * The hub holds the rules a leaked token could otherwise bend: the moderation
 * hold is worked out here, only approved pictures and staged game art can be
 * read, and only paths reserved for deletion can be deleted.
 */
final class PromotionJob
{
    /** What the job reads off a row it promotes. */
    private const PROMOTABLE_COLUMNS = 'id,game,unit_name,map_name,faction_key,variant,hash,mime,tier,path,bytes,updated_at';

    public const GAME_IMAGE_KINDS = ['logo', 'banner', 'card'];

    /** The most rows one request asks for. The job asks for 200. */
    public const MAX_LIMIT = 1000;

    public function __construct(private readonly Client $admin) {}

    /** Safety rejections of promoted pictures nobody has taken down yet, oldest first. @return list<array<string, mixed>> */
    public function withdrawals(): array
    {
        return $this->admin->from('asset_withdrawal')->select('asset_id,path,at')->is('withdrawn_at', null)->order('at')->get();
    }

    /** @param list<string> $ids */
    public function recordWithdrawn(array $ids): int
    {
        return $ids === [] ? 0 : (int) $this->admin->rpc('record_asset_withdrawn', ['ids' => $ids]);
    }

    /**
     * Approved pictures on the staging tier past the moderation hold, oldest
     * approval first, then by id, because a bulk approval gives hundreds of
     * rows the same time. Not one marked as missing its bytes (#336).
     *
     * @return list<array<string, mixed>>
     */
    public function due(int $limit, DateTimeInterface $now): array
    {
        return $this->admin->from('asset')->select(self::PROMOTABLE_COLUMNS)
            ->eq('tier', 'bucket')->eq('moderation', 'approved')->is('blob_path', null)->is('bytes_missing_at', null)
            ->lte('updated_at', Promotion::cutoff($now))->order('updated_at')->order('id')->limit($limit)->get();
    }

    /**
     * Which of these are still approved and on the staging tier, asked just
     * before the push.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    public function stillDue(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_column($this->admin->from('asset')->select('id')->in('id', $ids)
            ->eq('tier', 'bucket')->eq('moderation', 'approved')->is('bytes_missing_at', null)->get(), 'id');
    }

    /** Staged objects whose rows have moved and which are not deleted yet. @return list<array<string, mixed>> */
    public function pendingDeletions(): array
    {
        return $this->admin->from('asset')->select('id,path,tier,blob_path')->not('blob_path', 'is', null)->get();
    }

    /**
     * @param  list<string>  $ids
     * @param  list<string>  $paths
     * @return list<array<string, mixed>>
     */
    public function promote(array $ids, array $paths): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->admin->rpc('promote_assets', ['ids' => $ids, 'paths' => $paths]);

        return is_array($rows) ? $rows : [];
    }

    /** @param list<string> $ids */
    public function clearPromoted(array $ids): int
    {
        return $ids === [] ? 0 : (int) $this->admin->rpc('clear_promoted_blob_paths', ['ids' => $ids]);
    }

    /**
     * A staged object's bytes, when the job has a reason to read it: an
     * approved picture still on the staging tier, or a game picture staged in
     * the bucket. Null otherwise, so a pending or rejected upload cannot be
     * read with the job's token.
     */
    public function readStaged(string $path): ?string
    {
        $approved = $this->admin->from('asset')->select('id')->eq('path', $path)->eq('moderation', 'approved')
            ->neq('tier', 'static')->limit(1)->get() !== [];
        if (! $approved && ! in_array($path, $this->stagedGameArtPaths(), true)) {
            return null;
        }

        return $this->admin->downloadObject(StagedObjects::BUCKET, $path);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string> the paths reserved, which are the ones to delete
     */
    public function reserve(array $paths, bool $queuedIsClaimed): array
    {
        if ($paths === []) {
            return [];
        }
        $rows = $this->admin->rpc('reserve_staged_deletions', ['object_paths' => $paths, 'queued_is_claimed' => $queuedIsClaimed]);

        return array_values(array_map(fn (array $row) => (string) $row['object_path'], is_array($rows) ? $rows : []));
    }

    /**
     * Deletes staged objects, all of them or none. Each must be reserved
     * first, which only happens to a path nothing claims.
     *
     * @param  list<string>  $paths
     * @return list<string>|null the paths deleted, or null when one was not reserved
     */
    public function discard(array $paths): ?array
    {
        $paths = array_values(array_unique($paths));
        if ($paths === []) {
            return [];
        }
        $reserved = array_column($this->admin->from('staged_object_deletion')->select('path')->in('path', $paths)->order('path')->get(), 'path');
        if (array_diff($paths, $reserved) !== []) {
            return null;
        }
        $this->admin->removeObjects(StagedObjects::BUCKET, $paths);

        return $paths;
    }

    /** @param list<string> $paths */
    public function release(array $paths): int
    {
        return $paths === [] ? 0 : (int) $this->admin->rpc('release_staged_deletions', ['object_paths' => $paths]);
    }

    /** Every game's picture paths, hashes and staged tiers. @return list<array<string, mixed>> */
    public function games(): array
    {
        $columns = ['shortname'];
        foreach (self::GAME_IMAGE_KINDS as $kind) {
            array_push($columns, $kind.'_path', $kind.'_hash', $kind.'_staged_tier');
        }

        return $this->admin->from('game')->select(implode(',', $columns))->order('shortname')->get();
    }

    /**
     * Says a game picture has no staged copy any more, only while the row
     * still names the path and hash that were promoted. False when an upload
     * or a removal changed the row since the job read it.
     */
    public function clearGameStagedTier(string $shortname, string $kind, string $path, string $hash): bool
    {
        return $this->admin->from('game')->update([$kind.'_staged_tier' => null])
            ->eq('shortname', $shortname)->eq($kind.'_path', $path)->eq($kind.'_hash', $hash)->eq($kind.'_staged_tier', 'bucket')
            ->select('shortname')->get() !== [];
    }

    /** @return list<string> */
    private function stagedGameArtPaths(): array
    {
        $paths = [];
        foreach ($this->games() as $game) {
            foreach (self::GAME_IMAGE_KINDS as $kind) {
                if (($game[$kind.'_staged_tier'] ?? null) === 'bucket' && ($game[$kind.'_path'] ?? null) !== null) {
                    $paths[] = $game[$kind.'_path'];
                }
            }
        }

        return $paths;
    }
}
