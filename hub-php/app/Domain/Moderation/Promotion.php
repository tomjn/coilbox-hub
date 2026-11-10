<?php

namespace App\Domain\Moderation;

use App\Supabase\Client;
use App\Supabase\Query;
use App\Supabase\SupabaseException;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Where the staging tier stands, the status block from `lib/assets/promote.ts`
 * that the allowances page shows. The promotion job itself runs elsewhere.
 * Every count comes from Postgres, which is where the job decides from.
 */
final class Promotion
{
    /** How long an approval waits before the job moves it to the durable tier. */
    public const AGE_DAYS = 1;

    /**
     * How long past due a row can sit before the job has missed a run. The
     * job runs daily and is allowed 60 minutes, so a day and an hour.
     */
    public const STALL_HOURS = 25;

    private const DAY = 86400;

    /** The instant a row has to be older than, on `updated_at`, to be promoted. */
    public static function cutoff(DateTimeInterface $now): string
    {
        return Meters::iso(DateTimeImmutable::createFromInterface($now)->modify('-'.(self::AGE_DAYS * self::DAY).' seconds'));
    }

    /**
     * Every count is null when its query failed, which is not the same as none.
     *
     * @return array{waiting: int|null, due: int|null, dueSince: string|null, stalled: bool, pending: int|null, missing: int|null, leftover: int|null, promotedRecently: int|null, lastPromotedAt: string|null}
     */
    public static function status(Client $admin, DateTimeInterface $now): array
    {
        $cutoff = self::cutoff($now);
        $since = Meters::iso(DateTimeImmutable::createFromInterface($now)->modify('-'.(30 * self::DAY).' seconds'));
        $assets = fn () => $admin->from('asset')->select('id', 'exact', head: true);
        $approved = fn () => $assets()->eq('tier', 'bucket')->eq('moderation', 'approved')->is('blob_path', null)->is('bytes_missing_at', null);

        $oldest = self::first(fn () => $admin->from('asset')->select('updated_at')
            ->eq('tier', 'bucket')->eq('moderation', 'approved')->is('blob_path', null)->is('bytes_missing_at', null)
            ->lte('updated_at', $cutoff)->order('updated_at')->limit(1), 'updated_at');
        $dueSince = $oldest === null ? null
            : Meters::iso((new DateTimeImmutable($oldest))->modify('+'.(self::AGE_DAYS * self::DAY).' seconds'));

        return [
            'waiting' => self::count($approved()->gt('updated_at', $cutoff)),
            'due' => self::count($approved()->lte('updated_at', $cutoff)),
            'dueSince' => $dueSince,
            'stalled' => $dueSince !== null
                && $now->getTimestamp() - (new DateTimeImmutable($dueSince))->getTimestamp() > self::STALL_HOURS * 3600,
            'pending' => self::count($assets()->eq('tier', 'bucket')->eq('moderation', 'pending')),
            'missing' => self::count($assets()->not('bytes_missing_at', 'is', 'null')),
            'leftover' => self::count($assets()->not('blob_path', 'is', 'null')),
            'promotedRecently' => self::count($assets()->gte('promoted_at', $since)),
            'lastPromotedAt' => self::first(fn () => $admin->from('asset')->select('promoted_at')
                ->not('promoted_at', 'is', 'null')->order('promoted_at', ascending: false)->limit(1), 'promoted_at'),
        ];
    }

    private static function count(Query $query): ?int
    {
        try {
            return $query->getWithCount()['count'] ?? 0;
        } catch (SupabaseException) {
            return null;
        }
    }

    /** @param \Closure(): Query $query */
    private static function first(\Closure $query, string $column): ?string
    {
        try {
            $rows = $query()->get();
        } catch (SupabaseException) {
            return null;
        }

        return isset($rows[0][$column]) ? (string) $rows[0][$column] : null;
    }
}
