<?php

namespace App\Domain\Mirrors;

use App\Supabase\Client;
use App\Supabase\SupabaseException;
use App\Support\HubCache;

/**
 * Removing an uploaded archive and closing its mismatch reports. The database
 * decides who may do what, see `remove_archive` in
 * 20261117130000_hub_mirror_moderation.sql. A removed archive leaves every
 * mirror on its next sync, and its hash and name can never be uploaded again.
 */
final class Removal
{
    /** The reasons a moderator picks from. The catalogue sends the code to every mirror. */
    public const MODERATOR_REASONS = [
        'broken' => 'Broken, or will not load',
        'wrong_facts' => 'Not what it says it is',
        'rights' => 'Shared without the author\'s permission',
        'harmful' => 'Harmful or abusive',
        'other' => 'Something else, say why in the note',
    ];

    /** Every reason, as a person reads it afterwards. */
    public const LABELS = [
        'withdrawn' => 'Withdrawn by its uploader or the game\'s owner',
        'broken' => 'Broken, or will not load',
        'wrong_facts' => 'Not what it says it is',
        'rights' => 'Shared without the author\'s permission',
        'harmful' => 'Harmful or abusive',
        'other' => 'Removed by a moderator',
    ];

    private const MESSAGES = [
        'removed' => 'Removed. The hub stopped linking it, and mirrors delete it on their next sync.',
        'not_allowed' => 'You cannot remove this archive.',
        'not_listed' => 'Only an archive that is listed can be removed.',
        'not_found' => 'That archive no longer exists.',
        'bad_reason' => 'Pick a reason.',
        'note_required' => 'Say why in the note.',
        'note_too_long' => 'The note can be at most 2000 characters.',
    ];

    /** @return array{ok: bool, message: string} */
    public static function remove(Client $client, string $id, string $reason, ?string $note): array
    {
        try {
            $outcome = $client->rpc('remove_archive', ['p_id' => $id, 'p_reason' => $reason, 'p_note' => $note]);
        } catch (SupabaseException) {
            $outcome = null;
        }

        if ($outcome === 'removed') {
            // Map and game pages are cached for a week and list this archive's mirror copies.
            HubCache::invalidate([HubCache::MAPS, HubCache::GAMES]);
        }

        return [
            'ok' => $outcome === 'removed',
            'message' => is_string($outcome) && isset(self::MESSAGES[$outcome])
                ? self::MESSAGES[$outcome]
                : 'It could not be removed just now. Try again.',
        ];
    }

    /** @return array{ok: bool, message: string} */
    public static function dismiss(Client $client, string $archiveId): array
    {
        try {
            $count = $client->rpc('dismiss_archive_mismatches', ['p_archive' => $archiveId]);
        } catch (SupabaseException) {
            return ['ok' => false, 'message' => 'The reports could not be dismissed just now. Try again.'];
        }

        return is_int($count) && $count > 0
            ? ['ok' => true, 'message' => $count === 1 ? 'Dismissed 1 report.' : "Dismissed $count reports."]
            : ['ok' => false, 'message' => 'There were no open reports to dismiss.'];
    }

    /** Human words for a field name in a mismatch report. */
    public static function fieldLabel(string $field): string
    {
        return match ($field) {
            'kind' => 'Kind',
            'shortname' => 'Shortname',
            'internal_name' => 'Name',
            'version' => 'Version',
            default => $field,
        };
    }
}
