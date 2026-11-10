<?php

namespace App\Domain\Moderation;

use App\Supabase\Client;
use App\Supabase\SupabaseException;
use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

/**
 * The two reads the allowances page takes from `lib/assets/orphan.ts`
 * (issues #113 and #335): bucket objects no row claims, and deletions a dead
 * sweep reserved and never released. The sweep itself runs elsewhere.
 */
final class Orphans
{
    /** How many objects one sweep handles. */
    public const CLEANUP_BATCH = 200;

    /** A reservation older than the daily job's 60 minute limit belongs to no running job. */
    public const ABANDONED_RESERVATION_MINUTES = 60;

    /**
     * Bucket objects nothing claims, oldest first. Wants the secret key.
     *
     * @return list<array{path: string, bytes: int, created_at: string}>
     */
    public static function unclaimed(Client $admin, int $limit = self::CLEANUP_BATCH): array
    {
        try {
            $rows = $admin->rpc('unclaimed_staged_objects', ['max_objects' => $limit]);
        } catch (SupabaseException $e) {
            throw new RuntimeException('Could not list what nothing claims in the bucket: '.$e->getMessage(), 0, $e);
        }

        return array_map(fn (array $row) => [
            'path' => (string) $row['object_path'],
            'bytes' => (int) $row['bytes'],
            'created_at' => (string) $row['created_at'],
        ], is_array($rows) ? $rows : []);
    }

    /** @return list<string> */
    public static function abandonedDeletions(Client $admin, DateTimeInterface $now): array
    {
        $before = Meters::iso(DateTimeImmutable::createFromInterface($now)->modify('-'.(self::ABANDONED_RESERVATION_MINUTES * 60).' seconds'));
        try {
            $rows = $admin->from('staged_object_deletion')->select('path')->lt('reserved_at', $before)->order('reserved_at')->get();
        } catch (SupabaseException $e) {
            throw new RuntimeException('Could not read the reserved bucket deletions: '.$e->getMessage(), 0, $e);
        }

        return array_map(fn (array $row) => (string) $row['path'], $rows);
    }
}
