<?php

namespace App\Domain\Assets;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * A second set of source bytes from the same archive, `lib/assets/sourceConflict.ts`
 * in the Next app.
 *
 * Extraction is deterministic, so a different `source_hash` from the same
 * archive is odd: a modified client or a corrupted install. It is a signal for
 * the moderation grid and never a gate. A different archive is an ordinary
 * version rollover.
 */
final class SourceConflict
{
    /**
     * The disagreement, or null. A rejected row is out of the queue, so a mark
     * on it would be one nobody sees.
     *
     * @param  array{id: string, source_hash: string, source_archive: string, moderation: string}  $held
     * @param  array{sourceHash: string, sourceArchive: string}  $declared
     * @return array{assetId: string, sourceArchive: string, heldSourceHash: string, reportedSourceHash: string, reportedBy: string}|null
     */
    public static function detect(array $held, array $declared, string $reportedBy): ?array
    {
        if ($held['moderation'] === 'rejected'
            || $held['source_archive'] !== $declared['sourceArchive']
            || $held['source_hash'] === $declared['sourceHash']) {
            return null;
        }

        return [
            'assetId' => $held['id'],
            'sourceArchive' => $declared['sourceArchive'],
            'heldSourceHash' => $held['source_hash'],
            'reportedSourceHash' => $declared['sourceHash'],
            'reportedBy' => $reportedBy,
        ];
    }

    /**
     * Record one, best effort. An upsert that ignores duplicates, so a client
     * looping on the same refused upload leaves one row.
     *
     * @param  array{assetId: string, sourceArchive: string, heldSourceHash: string, reportedSourceHash: string, reportedBy: string}  $conflict
     */
    public static function record(Client $admin, array $conflict): bool
    {
        try {
            $admin->from('asset_source_conflict')->upsert([
                'asset_id' => $conflict['assetId'],
                'source_archive' => $conflict['sourceArchive'],
                'held_source_hash' => $conflict['heldSourceHash'],
                'reported_source_hash' => $conflict['reportedSourceHash'],
                'reported_by' => $conflict['reportedBy'],
            ], 'asset_id,reported_source_hash', true)->execute();

            return true;
        } catch (SupabaseException) {
            return false;
        }
    }
}
