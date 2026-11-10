<?php

namespace App\Domain\Moderation;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * Source disagreements on pictures already approved, `lib/assets/approvedConflicts.ts`
 * (issue #149). A list for a moderator, never a state change: moving an
 * approved picture on a stranger's report would let any account de-publish
 * pictures. Both calls use the moderator's own session, and the database
 * functions ask `is_moderator()` themselves.
 */
final class ApprovedConflicts
{
    /**
     * The open disagreements, oldest first, a queue page at a time. A failed
     * read comes back as an error so it does not read as an empty list.
     *
     * @return array{conflicts: list<array<string, mixed>>, total: int, error: string|null}
     */
    public static function fetch(Client $session, int $limit = Queue::PAGE_SIZE): array
    {
        try {
            $rows = $session->rpc('approved_source_conflicts', ['p_limit' => $limit]);
        } catch (SupabaseException $e) {
            return ['conflicts' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
        $rows = is_array($rows) ? $rows : [];

        return [
            'conflicts' => array_map(fn (array $row) => [
                'conflictId' => $row['conflict_id'],
                'assetId' => $row['asset_id'],
            ] + Queue::caption($row) + [
                'sourceArchive' => $row['source_archive'],
                'heldSourceHash' => $row['held_source_hash'],
                'reportedSourceHash' => $row['reported_source_hash'],
                'reportedBy' => $row['reported_by'],
                'uploadedBy' => $row['uploaded_by'],
                'at' => $row['at'],
            ], $rows),
            'total' => (int) ($rows[0]['total'] ?? 0),
            'error' => null,
        ];
    }

    /** Mark one as looked at. False when nothing was cleared or the call was refused. */
    public static function acknowledge(Client $session, int $id): bool
    {
        try {
            return $session->rpc('acknowledge_source_conflict', ['p_conflict_id' => $id]) === true;
        } catch (SupabaseException) {
            return false;
        }
    }

    /** An entry id off a form field, or null unless it is a plain positive whole number a JavaScript number holds exactly. */
    public static function conflictId(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            return null;
        }
        $id = (float) $value;
        if ($id > 9007199254740991) {
            return null;
        }

        return (int) $value > 0 ? (int) $value : null;
    }
}
