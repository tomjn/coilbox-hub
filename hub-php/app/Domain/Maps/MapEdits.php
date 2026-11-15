<?php

namespace App\Domain\Maps;

use App\Domain\Games\Download;
use App\Supabase\Client;
use App\Supabase\SupabaseException;
use App\Support\HubCache;
use Illuminate\Support\Facades\Log;

/**
 * The writes a map's owner, an owner of one of its authors, or a moderator
 * makes from the map's edit page, plus asking to own a map or an author.
 *
 * The rule lives in the database, in `public.can_edit_map`, which the
 * download source policies call. Each write asks it first with the visitor's
 * own client, so a refusal says so before anything is tried.
 */
final class MapEdits
{
    public const KINDS = ['url', 'github'];

    public const OWNERSHIP_MESSAGES = [
        'sent' => 'Request sent. A moderator will look at it.',
        'already' => 'You have already asked for that. A moderator will look at it.',
        'notSaved' => 'The request could not be sent. Try again in a few minutes.',
        'notSent' => 'The form did not reach the hub. Reload the page and try again.',
    ];

    public const DOWNLOADS_MESSAGES = [
        'saved' => 'Downloads saved.',
        'signedOut' => 'You are signed out. Sign in, then save your changes again.',
        'notAllowed' => 'You can no longer change this map. Only its owner, an owner of its author, or a moderator can save its downloads.',
        'notSaved' => 'The downloads could not be saved. Try again in a few minutes.',
        'notSent' => 'The form did not reach the hub. Reload the page and try again.',
    ];

    /**
     * The map behind a slug when this visitor may edit it, else null. Null
     * also covers an unknown slug and a failed read, so a stranger cannot
     * tell a map they may not edit from one that does not exist.
     *
     * @return array{id: string, map_name: string}|null
     */
    public static function editableMap(?Client $session, string $slug): ?array
    {
        if ($session === null || $slug === '') {
            return null;
        }
        try {
            $map = $session->from('map')->select('id, map_name')->eq('slug', $slug)->maybeSingle();
            if ($map === null) {
                return null;
            }

            return self::canEdit($session, (string) $map['id']) ? $map : null;
        } catch (SupabaseException) {
            return null;
        }
    }

    /** Whether this visitor may edit one map's sources. A failed read is no. */
    public static function canEdit(?Client $session, string $mapId): bool
    {
        if ($session === null) {
            return false;
        }
        try {
            return $session->rpc('can_edit_map', ['p_map_id' => $mapId]) === true;
        } catch (SupabaseException) {
            return false;
        }
    }

    /**
     * One map's sources as the edit form shows them, in order.
     *
     * @return list<array<string, mixed>>
     */
    public static function downloads(Client $session, string $mapId): array
    {
        try {
            return Download::readDownloads($session->from('map_download_source')->select('kind, value, asset, filename')
                ->eq('map_id', $mapId)->order('sort_order')->order('id')->get());
        } catch (SupabaseException) {
            return [];
        }
    }

    /**
     * The ordered sources, replaced whole on every save. The new list goes in
     * before the old one comes out, the order game sources are saved in, so a
     * failed insert leaves the list the editor had.
     *
     * @return array{ok: bool, message: string}
     */
    public static function editDownloadSources(?Client $session, ?string $userId, string $slug, string $payload): array
    {
        $messages = self::DOWNLOADS_MESSAGES;
        if ($slug === '') {
            return self::no($messages['notSent']);
        }
        if ($session === null || $userId === null) {
            return self::no($messages['signedOut']);
        }
        $parsed = Download::parseDownloads($payload, self::KINDS, 'map');
        if (! $parsed['ok']) {
            return self::no($parsed['message']);
        }
        $map = self::editableMap($session, $slug);
        if ($map === null) {
            return self::no($messages['notAllowed']);
        }

        try {
            $held = $session->from('map_download_source')->select('id')->eq('map_id', $map['id'])->get();
        } catch (SupabaseException $e) {
            Log::error("editMapDownloadSources: {$slug} could not be read", ['error' => $e->getMessage()]);

            return self::no($messages['notSaved']);
        }

        if ($parsed['downloads'] !== []) {
            $rows = [];
            foreach ($parsed['downloads'] as $index => $download) {
                $rows[] = [
                    'map_id' => $map['id'],
                    'kind' => $download['kind'],
                    'value' => $download['value'],
                    'asset' => $download['asset'] ?? null,
                    'filename' => $download['filename'] ?? null,
                    'sort_order' => $index,
                ];
            }
            try {
                $session->from('map_download_source')->insert($rows)->execute();
            } catch (SupabaseException $e) {
                Log::error("editMapDownloadSources: {$slug} was not saved", ['error' => $e->getMessage()]);

                return self::no($messages['notSaved']);
            }
        }

        $replaced = array_map(fn (array $row) => $row['id'], $held);
        if ($replaced !== []) {
            try {
                $session->from('map_download_source')->delete()->in('id', $replaced)->execute();
            } catch (SupabaseException $e) {
                Log::error("editMapDownloadSources: {$slug} kept its old sources", ['error' => $e->getMessage()]);

                return self::no($messages['notSaved']);
            }
        }

        HubCache::invalidate([HubCache::map($slug)]);

        return ['ok' => true, 'message' => $messages['saved']];
    }

    /**
     * What a signed in visitor may ask to own from this map's page: the map
     * when nobody owns it, and each credited author nobody owns.
     *
     * @param  array<string, mixed>  $page  {@see MapPage::load()}
     * @return list<array{value: string, label: string}>
     */
    public static function ownershipTargets(array $page): array
    {
        $targets = [];
        if (($page['ownerUserId'] ?? null) === null) {
            $targets[] = ['value' => 'map', 'label' => 'This map'];
        }
        foreach ($page['facts']['authors'] ?? [] as $author) {
            if (! in_array($author['key'], $page['ownedAuthorKeys'] ?? [], true)) {
                $targets[] = ['value' => 'author:'.$author['key'], 'label' => 'Every map by '.$author['name']];
            }
        }

        return $targets;
    }

    /**
     * Ask to own a map or one of its authors. Only a target the page offered
     * is accepted, so nobody files a request for an author the map does not
     * credit. Answers which of {@see OWNERSHIP_MESSAGES} to show.
     */
    public static function requestOwnership(Client $session, string $userId, string $slug, string $target, string $note): string
    {
        $page = MapPage::cached($slug);
        if ($page === null || ! in_array($target, array_column(self::ownershipTargets($page), 'value'), true)) {
            return 'notSent';
        }
        $note = mb_substr(trim($note), 0, 2000);
        $row = ['requested_by' => $userId, 'note' => $note !== '' ? $note : null];
        $row += $target === 'map' ? ['map_id' => $page['mapId']] : ['author_key' => substr($target, strlen('author:'))];
        try {
            $session->from('map_ownership_request')->insert([$row])->execute();
        } catch (SupabaseException $e) {
            // The open request index: this person is already asking for this.
            return $e->errorCode === '23505' ? 'already' : 'notSaved';
        }

        return 'sent';
    }

    private static function no(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
