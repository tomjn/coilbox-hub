<?php

namespace App\Domain\Moderation;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * The account directory behind `/moderation/users`, `lib/moderation/accounts.ts`
 * (issue #385). The database function refuses a non-moderator itself.
 */
final class Accounts
{
    /** The database function caps every answer at this. */
    public const PAGE_SIZE = 50;

    /** Labels for the capability chips. An unknown capability shows as itself. */
    public const CAPABILITY_LABELS = [
        'can_moderate' => 'moderator',
        'can_publish_unreviewed' => 'publishes unreviewed',
        'can_seed_unit_assets' => 'seeds pictures',
        'can_replace_any_asset' => "replaces others' pictures",
    ];

    /**
     * One page of accounts, newest first. An error comes back as a string, so
     * a directory that failed reads as a failure and not as an empty hub.
     *
     * @return array{accounts: list<array{id: string, display_name: string, created_at: string, capabilities: list<string>}>, error: string|null}
     */
    public static function fetch(Client $session, ?string $search, int $offset): array
    {
        try {
            $rows = $session->rpc('moderator_accounts', ['p_search' => $search, 'p_offset' => $offset]);
        } catch (SupabaseException $e) {
            return ['accounts' => [], 'error' => $e->getMessage()];
        }

        return ['accounts' => is_array($rows) ? $rows : [], 'error' => null];
    }
}
