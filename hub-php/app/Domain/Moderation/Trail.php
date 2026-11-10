<?php

namespace App\Domain\Moderation;

use App\Supabase\Client;
use App\Supabase\SupabaseException;
use RuntimeException;

/**
 * Reading `public.asset_event` back, `lib/assets/trail.ts` (issue #115).
 *
 * Unwinding an account gone bad needs two lists: what it uploaded, off
 * `asset.uploaded_by`, and what it did, off `asset_event.actor`. Neither
 * contains the other. Everything reads with the secret key, because
 * `asset_event` grants browser roles nothing.
 */
final class Trail
{
    /** A day of moderation in one look, and bounded because the table only grows. */
    public const PAGE_SIZE = 200;

    private const EVENT_COLUMNS = 'id, action, rejection_kind, actor, uploader, uploader_display_name, uploader_provider_name, at, asset(id, game, unit_name, map_name, faction_key, variant)';

    private const ACCOUNT_ASSET_COLUMNS = 'id, game, unit_name, map_name, faction_key, variant, moderation, rejection_kind, approval_source, origin, created_at';

    /**
     * Everything lately, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(Client $admin, int $limit = self::PAGE_SIZE): array
    {
        try {
            $rows = $admin->from('asset_event')->select(self::EVENT_COLUMNS)->order('at', ascending: false)->limit($limit)->get();
        } catch (SupabaseException) {
            $rows = [];
        }

        return self::toEvents($admin, $rows);
    }

    /**
     * Everything one account is behind, as decider or uploader. The account
     * goes into a PostgREST `or` filter, which is a grammar rather than a
     * bound parameter, so it is held to the shape of a uuid here too.
     *
     * @return list<array<string, mixed>>
     */
    public static function accountEvents(Client $admin, string $account, int $limit = self::PAGE_SIZE): array
    {
        if (! Queue::isUuid($account)) {
            return [];
        }
        try {
            $rows = $admin->from('asset_event')->select(self::EVENT_COLUMNS)
                ->or("actor.eq.{$account},uploader.eq.{$account}")
                ->order('at', ascending: false)->limit($limit)->get();
        } catch (SupabaseException) {
            $rows = [];
        }

        return self::toEvents($admin, $rows);
    }

    /**
     * One account's whole record. A picture in both lists is one the account
     * uploaded and then had a decision made about, so nothing is deduplicated.
     *
     * @return array{uploaded: list<array<string, mixed>>, uploadedTotal: int, events: list<array<string, mixed>>}
     */
    public static function account(Client $admin, string $account, int $limit = self::PAGE_SIZE): array
    {
        try {
            $assets = $admin->from('asset')->select(self::ACCOUNT_ASSET_COLUMNS, 'exact')
                ->eq('uploaded_by', $account)->order('created_at', ascending: false)->limit($limit)->getWithCount();
        } catch (SupabaseException) {
            $assets = ['rows' => [], 'count' => null];
        }
        $rows = $assets['rows'];

        return [
            'uploaded' => array_map(fn (array $row) => ['id' => $row['id']] + Queue::caption($row) + [
                'moderation' => $row['moderation'],
                'rejectionKind' => $row['rejection_kind'],
                'approvalSource' => $row['approval_source'],
                'origin' => $row['origin'],
                'createdAt' => $row['created_at'],
            ], $rows),
            'uploadedTotal' => $assets['count'] ?? count($rows),
            'events' => self::accountEvents($admin, $account, $limit),
        ];
    }

    /**
     * The events, each kept name marked by whether its account still exists.
     * Every account has a profile row from the start and it goes with the
     * account, so a missing profile is a closed account.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function toEvents(Client $admin, array $rows): array
    {
        $named = [];
        foreach ($rows as $row) {
            if ($row['uploader'] && ($row['uploader_display_name'] || $row['uploader_provider_name'])) {
                $named[$row['uploader']] = true;
            }
        }
        $open = [];
        if ($named !== []) {
            try {
                $profiles = $admin->from('profile')->select('user_id')->in('user_id', array_keys($named))->get();
            } catch (SupabaseException $e) {
                throw new RuntimeException('Could not check which uploaders still have accounts: '.$e->getMessage(), 0, $e);
            }
            foreach ($profiles as $profile) {
                $open[$profile['user_id']] = true;
            }
        }

        return array_map(fn (array $row) => self::toEvent($row, $open), $rows);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, true>  $open
     * @return array<string, mixed>
     */
    private static function toEvent(array $row, array $open): array
    {
        $asset = $row['asset'] ?? null;
        $caption = is_array($asset) ? Queue::caption($asset) : ['name' => 'A picture that is no longer there', 'detail' => ''];

        return [
            'id' => $row['id'],
            'action' => $row['action'],
            'rejectionKind' => $row['rejection_kind'],
            'actor' => $row['actor'],
            'uploader' => $row['uploader'],
            'uploaderName' => $row['uploader_display_name'] === null && $row['uploader_provider_name'] === null ? null : [
                'displayName' => $row['uploader_display_name'],
                'providerName' => $row['uploader_provider_name'],
                'accountClosed' => ! $row['uploader'] || ! isset($open[$row['uploader']]),
            ],
            'at' => $row['at'],
            'assetId' => is_array($asset) ? $asset['id'] : '',
        ] + $caption;
    }

    /**
     * How an event reads in a sentence. A rejection names its kind in the line
     * itself, so a reader skimming cannot mistake one kind for the other.
     *
     * @param  array<string, mixed>  $event
     */
    public static function eventLine(array $event): string
    {
        return match ($event['action']) {
            'seeded' => 'Seeded straight into the corpus',
            'bypassed' => 'Published without review, on a capability',
            'approved' => 'Approved by a moderator',
            'returned' => 'Returned to the queue',
            'conflict_acknowledged' => 'Source disagreement looked at and cleared, picture left as it was',
            'replaced' => "Replaced another account's picture, on a capability",
            'rejected' => $event['rejectionKind'] === 'safety'
                ? 'Rejected on safety grounds, which is final'
                : 'Rejected as '.$event['rejectionKind'],
            default => (string) $event['action'],
        };
    }

    /**
     * Who uploaded the picture, by the names a safety rejection kept (#146),
     * or null where none were kept. Once the account has closed these names
     * are all that is left of it, and the line says so.
     *
     * @param  array<string, mixed>  $event
     */
    public static function uploaderNameLine(array $event): ?string
    {
        $kept = $event['uploaderName'] ?? null;
        if (! $kept || (! $kept['displayName'] && ! $kept['providerName'])) {
            return null;
        }
        $names = implode(', ', array_filter([
            $kept['displayName'] ? 'Uploaded by '.$kept['displayName'] : 'Uploaded',
            $kept['providerName'] ? 'signed in as '.$kept['providerName'] : null,
        ]));

        return $kept['accountClosed'] ? $names.'. Name kept after the account closed.' : $names;
    }
}
