<?php

namespace App\Domain\Moderation;

use App\Domain\Assets\Asset;
use App\Supabase\Client;
use App\Supabase\SupabaseException;
use RuntimeException;

/**
 * What the hub is spending against the allowances that can end it,
 * `lib/assets/meters.ts` (issue #113).
 *
 * Neither the host nor Supabase tells an application what it has used, so
 * every meter says how its number was arrived at: `counted` from Postgres,
 * `estimated` from Postgres for something it only partly describes, or
 * `dashboard`, which has no number at all. A stale constant shown as if it
 * were live would be worse than no meter.
 */
final class Meters
{
    private const GIB = 1024 * 1024 * 1024;

    private const VERCEL_WINDOW_DAYS = 30;

    public const VERCEL_FAST_DATA_TRANSFER_ALLOWANCE_BYTES = 100 * self::GIB;

    /** GitHub Pages publishes at most 1 GB, and asks for under 100 GB of bandwidth a month. */
    public const PAGES_PUBLISHED_ALLOWANCE_BYTES = self::GIB;

    public const PAGES_BANDWIDTH_SOFT_ALLOWANCE_BYTES = 100 * self::GIB;

    /** Supabase's free plan file storage, https://supabase.com/pricing. */
    public const SUPABASE_STORAGE_ALLOWANCE_BYTES = self::GIB;

    /** 5 GB cached and 5 GB uncached egress, shared across the organisation. */
    public const SUPABASE_EGRESS_ALLOWANCE_BYTES = 10 * self::GIB;

    /** How full a counted meter is before the daily job fails. One fraction for all of them. */
    public const ALERT_FRACTION = 0.75;

    /** Render angles are one class: the angle is identity, not accounting. */
    public static function assetClass(string $variant): string
    {
        return str_starts_with($variant, Asset::unitRenderVariantPrefix()) ? 'render' : $variant;
    }

    /**
     * The durable tier by class, largest first.
     *
     * @param  list<array{tier: string, variant: string, objects: int, bytes: int}>  $rows
     * @return list<array{name: string, objects: int, bytes: int}>
     */
    public static function durableClasses(array $rows): array
    {
        $classes = [];
        foreach ($rows as $row) {
            if ($row['tier'] !== 'static') {
                continue;
            }
            $name = self::assetClass($row['variant']);
            $classes[$name] ??= ['name' => $name, 'objects' => 0, 'bytes' => 0];
            $classes[$name]['objects'] += (int) $row['objects'];
            $classes[$name]['bytes'] += (int) $row['bytes'];
        }
        $classes = array_values($classes);
        usort($classes, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);

        return $classes;
    }

    /**
     * Every meter, read now, with the secret key: both functions are granted
     * to nothing else, and the counts include rows `asset_read_approved` hides.
     *
     * @return array{at: string, durable: list<array<string, mixed>>, meters: list<array<string, mixed>>}
     */
    public static function fetch(Client $admin, \DateTimeInterface $now): array
    {
        try {
            $rows = $admin->rpc('asset_storage_usage');
        } catch (SupabaseException $e) {
            throw new RuntimeException('Could not read what the stores hold: '.$e->getMessage(), 0, $e);
        }
        // Null is not zero. A broken query read as an empty store would report
        // full headroom at the moment nobody can check.
        try {
            $bucketBytes = (int) ($admin->rpc('staged_pictures_bucket_bytes') ?? 0);
        } catch (SupabaseException) {
            $bucketBytes = null;
        }
        $rows = is_array($rows) ? $rows : [];
        $static = 0;
        foreach ($rows as $row) {
            if ($row['tier'] === 'static') {
                $static += (int) $row['bytes'];
            }
        }

        return [
            'at' => self::iso($now),
            'durable' => self::durableClasses($rows),
            'meters' => [
                [
                    'name' => 'Supabase Storage',
                    'basis' => 'counted',
                    'used' => $bucketBytes,
                    'allowance' => self::SUPABASE_STORAGE_ALLOWANCE_BYTES,
                    'note' => 'Every object in the staged-pictures bucket, summed by storage.get_size_by_bucket(). '
                        .'Every upload counts here whatever its moderation state: a rejected picture (#348) is '
                        .'still bytes in the bucket until somebody decides what happens to it.',
                ],
                [
                    'name' => 'Supabase egress this month',
                    'basis' => 'dashboard',
                    'used' => null,
                    'allowance' => self::SUPABASE_EGRESS_ALLOWANCE_BYTES,
                    'note' => '5 GB cached and 5 GB uncached, shared by the database, auth and storage across the '
                        .'whole organisation and not just this project. /assets/staged/ reads a staged picture '
                        ."out of the bucket whenever Vercel's CDN has no copy, which includes the first view "
                        .'after every deployment, and the CDN answers the rest. Not exposed to the '
                        .'application. Read it off the project dashboard.',
                ],
                [
                    'name' => 'Vercel fast data transfer, last '.self::VERCEL_WINDOW_DAYS.' days',
                    'basis' => 'dashboard',
                    'used' => null,
                    'allowance' => self::VERCEL_FAST_DATA_TRANSFER_ALLOWANCE_BYTES,
                    'note' => 'Everything the site serves, including every view of a staged picture, since those '
                        .'come through /assets/staged/ rather than straight from a store. Measured by the '
                        .'platform and not exposed to the application. Read it off the project dashboard.',
                ],
                [
                    'name' => 'Durable tier published size',
                    'basis' => 'estimated',
                    'used' => $static,
                    'allowance' => self::PAGES_PUBLISHED_ALLOWANCE_BYTES,
                    'note' => 'Summed from the rows that say they are on the durable tier. An estimate of the '
                        .'published site rather than a measurement of it: the site also holds the atlas, the '
                        .'notice and anything committed by hand, and git objects are not row bytes. See the '
                        .'breakdown by class.',
                ],
                [
                    'name' => 'GitHub Pages bandwidth this month',
                    'basis' => 'dashboard',
                    'used' => null,
                    'allowance' => self::PAGES_BANDWIDTH_SOFT_ALLOWANCE_BYTES,
                    'note' => 'A soft limit, and GitHub publishes no figure for it anywhere a job could read. There '
                        .'is nothing to report until somebody is emailed about it.',
                ],
            ],
        ];
    }

    /** How full a meter is, or null when nothing measured it. @param array<string, mixed> $meter */
    public static function headroom(array $meter): ?float
    {
        return $meter['used'] === null ? null : $meter['used'] / $meter['allowance'];
    }

    /**
     * The meters past the alert fraction, as lines to print. A `dashboard`
     * meter has no number, so it can neither raise nor clear an alert.
     *
     * @param  array{meters: list<array<string, mixed>>}  $report
     * @return list<string>
     */
    public static function headroomAlerts(array $report): array
    {
        $out = [];
        foreach ($report['meters'] as $meter) {
            $full = self::headroom($meter);
            if ($full !== null && $full >= self::ALERT_FRACTION) {
                $out[] = $meter['name'].': '.self::formatBytes($meter['used'] ?? 0).' of '.self::formatBytes($meter['allowance'])
                    .', which is past '.round(self::ALERT_FRACTION * 100).'% of the allowance.';
            }
        }

        return $out;
    }

    /** A byte count in binary units, which is what the allowances are written in. */
    public static function formatBytes(int|float $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB'];
        $value = $bytes;
        $at = 0;
        while ($value >= 1024 && $at < count($units) - 1) {
            $value /= 1024;
            $at++;
        }

        return ($at === 0 ? (string) $value : number_format($value, 1, '.', '')).' '.$units[$at];
    }

    /** `Date.toISOString()`. */
    public static function iso(\DateTimeInterface $at): string
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
