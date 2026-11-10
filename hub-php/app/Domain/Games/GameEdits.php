<?php

namespace App\Domain\Games;

use App\Domain\Assets\ImageHeader;
use App\Domain\Assets\Staging;
use App\Supabase\Client;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use App\Support\HubCache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * The writes an owner or a moderator makes from a game's edit page and its
 * unit pages, from `app/games/actions.ts`: the words, the conquest factions,
 * the download sources, the pictures and a unit's snippet, plus asking to own
 * a game. Hiding, featuring and deciding offers are {@see \App\Domain\Moderation\GameDecisions}.
 *
 * The rules live in the database: policies and column grants refuse anything
 * a caller may not write. Each edit also asks {@see Editor::editableGame()}
 * first, with the visitor's own client, so a refusal says so before any write
 * is tried. The secret key is spent only after that check found the game.
 *
 * Every method answers `['ok' => bool, 'message' => string]`, the shape
 * `useActionState` showed beside the form. A null session means signed out.
 */
final class GameEdits
{
    public const EDIT_MESSAGES = [
        'saved' => 'Details saved.',
        'signedOut' => 'You are signed out. Sign in, then save your changes again.',
        'notAllowed' => 'You can no longer change this game. Only its owner or a moderator can save these details.',
        'notSaved' => 'The details could not be saved. Try again in a few minutes.',
        'notSent' => 'The form did not reach the hub. Reload the page and try again.',
    ];

    public const CONQUEST_FACTIONS_MESSAGES = [
        'saved' => 'Factions saved.',
        'signedOut' => 'You are signed out. Sign in, then save your changes again.',
        'notAllowed' => 'You can no longer change this game. Only its owner or a moderator can save its factions.',
        'notSaved' => 'The factions could not be saved. Try again in a few minutes.',
        'notSent' => 'The form did not reach the hub. Reload the page and try again.',
    ];

    public const DOWNLOADS_MESSAGES = [
        'saved' => 'Downloads saved.',
        'signedOut' => 'You are signed out. Sign in, then save your changes again.',
        'notAllowed' => 'You can no longer change this game. Only its owner or a moderator can save its downloads.',
        'notSaved' => 'The downloads could not be saved. Try again in a few minutes.',
        'notSent' => 'The form did not reach the hub. Reload the page and try again.',
    ];

    public const SNIPPET_MESSAGES = [
        'saved' => 'Snippet saved.',
        'signedOut' => 'You are signed out. Sign in, then save the snippet again.',
        'notAllowed' => 'You can no longer change this game. Only its owner or a moderator can save its snippets.',
        'notSaved' => 'The snippet could not be saved. Try again in a few minutes.',
        'notSent' => 'The form did not reach the hub. Reload the page and try again.',
    ];

    /** The most links and conquest factions a game keeps. */
    public const MAX_ROWS = 12;

    /**
     * Ask to own a game. The policy refuses a second open ask and a forged
     * requester, and both arrive as a failed write, which here is the right
     * answer: the button says what it does and the queue says what happened.
     */
    public static function requestOwnership(Client $session, string $userId, string $shortname, string $note): void
    {
        $note = mb_substr(trim($note), 0, 2000);
        try {
            $game = $session->from('game')->select('id')->eq('shortname', $shortname)->maybeSingle();
            if ($game === null) {
                return;
            }
            $session->from('game_ownership_request')->insert([
                'game_id' => $game['id'],
                'requested_by' => $userId,
                'note' => $note !== '' ? $note : null,
            ])->execute();
        } catch (SupabaseException) {
        }
    }

    /**
     * The words form: display name, description and links.
     *
     * @param  list<mixed>  $labels
     * @param  list<mixed>  $urls
     * @return array{ok: bool, message: string}
     */
    public static function editGameDetails(?Client $session, ?string $userId, string $shortname, string $displayName, string $description, array $labels, array $urls): array
    {
        $messages = self::EDIT_MESSAGES;

        return self::updateGame($messages, $session, $userId, $shortname, [
            'display_name' => self::orNull(mb_substr(trim($displayName), 0, 256)),
            'description' => self::orNull(mb_substr(trim($description), 0, 4000)),
            'links' => self::linksFromForm($labels, $urls),
        ], 'editGameDetails');
    }

    /** @return array{ok: bool, message: string} */
    public static function editConquestFactions(?Client $session, ?string $userId, string $shortname, string $factions): array
    {
        return self::updateGame(self::CONQUEST_FACTIONS_MESSAGES, $session, $userId, $shortname, [
            'conquest_factions' => self::conquestFactionsFromForm($factions),
        ], 'editConquestFactions');
    }

    /**
     * The ordered download sources, replaced whole on every save. The new
     * list goes in before the old one comes out, and the old rows are named by
     * the ids read a moment ago. Two writes cannot be one transaction from
     * here, so this picks the safer half-done state: a failed insert leaves
     * the list the owner had, a failed delete leaves it twice over, which they
     * can see and save away.
     *
     * @return array{ok: bool, message: string}
     */
    public static function editDownloadSources(?Client $session, ?string $userId, string $shortname, string $payload): array
    {
        $messages = self::DOWNLOADS_MESSAGES;
        if ($shortname === '') {
            return self::no($messages['notSent']);
        }
        if ($session === null || $userId === null) {
            return self::no($messages['signedOut']);
        }
        // Refused before the write, so a mistyped tag says which source and why.
        $parsed = Download::parseDownloads($payload);
        if (! $parsed['ok']) {
            return self::no($parsed['message']);
        }
        // Asked first, because a stranger's delete and an emptied list both
        // touch no row, and only this tells them apart.
        $game = Editor::editableGame($session, $userId, $shortname);
        if ($game === null) {
            return self::no($messages['notAllowed']);
        }

        try {
            $held = $session->from('game_download_source')->select('id')->eq('game_id', $game['id'])->get();
        } catch (SupabaseException $e) {
            Log::error("editDownloadSources: {$shortname} could not be read", ['error' => $e->getMessage()]);

            return self::no($messages['notSaved']);
        }

        if ($parsed['downloads'] !== []) {
            $rows = [];
            foreach ($parsed['downloads'] as $index => $download) {
                $rows[] = [
                    'game_id' => $game['id'],
                    'kind' => $download['kind'],
                    'value' => $download['value'],
                    'asset' => $download['asset'] ?? null,
                    'filename' => $download['filename'] ?? null,
                    'sort_order' => $index,
                ];
            }
            try {
                $session->from('game_download_source')->insert($rows)->execute();
            } catch (SupabaseException $e) {
                Log::error("editDownloadSources: {$shortname} was not saved", ['error' => $e->getMessage()]);

                return self::no($messages['notSaved']);
            }
        }

        $replaced = array_map(fn (array $row) => $row['id'], $held);
        if ($replaced !== []) {
            try {
                $session->from('game_download_source')->delete()->in('id', $replaced)->execute();
            } catch (SupabaseException $e) {
                Log::error("editDownloadSources: {$shortname} kept its old sources", ['error' => $e->getMessage()]);

                return self::no($messages['notSaved']);
            }
        }

        HubCache::invalidate([HubCache::GAMES]);

        return ['ok' => true, 'message' => $messages['saved']];
    }

    /**
     * A unit's author snippet. The unit's page at every release shows it, so
     * the unit's own tag goes too.
     *
     * @return array{ok: bool, message: string}
     */
    public static function setSnippet(?Client $session, ?string $userId, string $shortname, string $unitName, string $snippet): array
    {
        $messages = self::SNIPPET_MESSAGES;
        if ($shortname === '' || $unitName === '') {
            return self::no($messages['notSent']);
        }
        if ($session === null || $userId === null) {
            return self::no($messages['signedOut']);
        }
        $game = Editor::editableGame($session, $userId, $shortname);
        if ($game === null) {
            return self::no($messages['notAllowed']);
        }
        $snippet = mb_substr(trim($snippet), 0, 2000);
        try {
            $rows = $session->from('game_unit')->update(['snippet' => self::orNull($snippet)])
                ->eq('game_id', $game['id'])->eq('unit_name', $unitName)->select('unit_name')->get();
        } catch (SupabaseException $e) {
            Log::error("setSnippet: {$shortname}/{$unitName} was not updated", ['error' => $e->getMessage()]);

            return self::no($messages['notSaved']);
        }
        if ($rows === []) {
            return self::no($messages['notAllowed']);
        }
        HubCache::invalidate([HubCache::unit($shortname, $unitName)]);

        return ['ok' => true, 'message' => $messages['saved']];
    }

    /**
     * A logo, banner or card from the edit page. The art columns are not in
     * the signed in grant, so the row write needs the secret key, and it earns
     * that only after the visitor's own client finds the game editable.
     *
     * @return array{ok: bool, message: string}
     */
    public static function uploadGameImage(?Client $session, ?string $userId, string $shortname, string $kind, mixed $file, string $note = ''): array
    {
        $messages = ImageUpload::UPLOAD_MESSAGES;
        if ($shortname === '' || ! in_array($kind, GameArt::KINDS, true)) {
            return self::no($messages['notSent']);
        }
        $refusal = ImageUpload::refuseImageFile($file);
        if ($refusal !== null) {
            return $refusal;
        }
        if ($session === null || $userId === null) {
            return self::no($messages['signedOut']);
        }
        $owned = Editor::editableGame($session, $userId, $shortname);
        if ($owned === null) {
            return self::no($messages['notAllowed']);
        }

        $bytes = (string) file_get_contents($file->getRealPath());
        $header = ImageHeader::read(substr($bytes, 0, ImageHeader::BYTES));
        if ($header === null) {
            return self::no($messages['wrongType']);
        }
        $path = 'games/'.$shortname.'/'.$kind.'.'.($header['mime'] === 'image/png' ? 'png' : 'webp');

        $admin = self::admin();
        if ($admin === null) {
            Log::error("uploadGameImage: no secret key, so {$path} was not stored");

            return self::no($messages['notSaved']);
        }
        try {
            Staging::putGameImage($admin, $path, $bytes, $header['mime']);
        } catch (SupabaseException|ConnectionException $e) {
            Log::error("uploadGameImage: the bucket refused {$path}", ['error' => $e->getMessage()]);

            return self::no($messages['notSaved']);
        }
        // The hash is over the bytes, so a re-upload of the same picture is
        // visible as no change and a cache can key on it.
        try {
            $admin->from('game')->update([
                $kind.'_path' => $path,
                $kind.'_hash' => hash('sha256', $bytes),
                // Which store holds the staged copy, so promotion reads the bucket.
                $kind.'_staged_tier' => 'bucket',
            ])->eq('id', $owned['id'])->execute();
        } catch (SupabaseException $e) {
            Log::error("uploadGameImage: stored {$path} but the game row was not updated", ['error' => $e->getMessage()]);

            return self::no($messages['notSaved']);
        }
        HubCache::invalidate([HubCache::GAMES]);
        $note = ImageUpload::note($note);

        return ['ok' => true, 'message' => trim($note.' '.ImageUpload::LABELS[$kind].' uploaded.')];
    }

    /**
     * Take a picture off again. Only the row is written. Once it names no
     * path nothing links the staged copy, and the daily sweep deletes it from
     * the bucket. Deleting it here would mean reserving it from a web request,
     * and a request that died holding the reservation would refuse the next
     * upload to that path until the sweep ran.
     *
     * @return array{ok: bool, message: string}
     */
    public static function removeGameImage(?Client $session, ?string $userId, string $shortname, string $kind): array
    {
        $messages = ImageUpload::REMOVE_MESSAGES;
        if ($shortname === '' || ! in_array($kind, GameArt::KINDS, true)) {
            return self::no($messages['notSent']);
        }
        if ($session === null || $userId === null) {
            return self::no($messages['signedOut']);
        }
        $owned = Editor::editableGame($session, $userId, $shortname);
        if ($owned === null) {
            return self::no($messages['notAllowed']);
        }
        $admin = self::admin();
        if ($admin === null) {
            Log::error("removeGameImage: no secret key, so the {$kind} of {$shortname} was not removed");

            return self::no($messages['notSaved']);
        }
        try {
            $admin->from('game')->update([$kind.'_path' => null, $kind.'_hash' => null, $kind.'_staged_tier' => null])
                ->eq('id', $owned['id'])->execute();
        } catch (SupabaseException $e) {
            Log::error("removeGameImage: the game row for {$shortname} would not clear its {$kind}", ['error' => $e->getMessage()]);

            return self::no($messages['notSaved']);
        }
        HubCache::invalidate([HubCache::GAMES]);

        return ['ok' => true, 'message' => ImageUpload::LABELS[$kind].' removed.'];
    }

    /**
     * The labelled links, from parallel `label[]` and `url[]` fields paired by
     * position. A pair with either half blank is dropped, because a link that
     * renders as an empty anchor is worse than no link.
     *
     * @param  list<mixed>  $labels
     * @param  list<mixed>  $urls
     * @return list<array{label: string, url: string}>
     */
    public static function linksFromForm(array $labels, array $urls): array
    {
        $links = [];
        foreach (array_values($labels) as $index => $label) {
            $label = trim(is_string($label) ? $label : '');
            $url = trim(is_string($urls[$index] ?? null) ? $urls[$index] : '');
            if ($label === '' || $url === '') {
                continue;
            }
            $links[] = ['label' => $label, 'url' => $url];
        }

        return array_slice($links, 0, self::MAX_ROWS);
    }

    /**
     * The factions, posted as one JSON array because the list is reordered in
     * the browser before it is sent. Anything that does not parse is treated
     * as nothing, the way a malformed stored row is.
     *
     * @return list<array{name: string, color?: string, side?: string}>
     */
    public static function conquestFactionsFromForm(string $payload): array
    {
        $parsed = json_decode($payload === '' ? '[]' : $payload, true);

        return array_slice(Catalog::parseConquestFactions(json_last_error() === JSON_ERROR_NONE ? $parsed : []), 0, self::MAX_ROWS);
    }

    /**
     * An update of columns the signed in grant allows, with the visitor's own
     * client. The policies filter out a row a stranger may not touch, so an
     * update over no row is a refusal too.
     *
     * @param  array<string, string>  $messages
     * @param  array<string, mixed>  $values
     * @return array{ok: bool, message: string}
     */
    private static function updateGame(array $messages, ?Client $session, ?string $userId, string $shortname, array $values, string $action): array
    {
        if ($shortname === '') {
            return self::no($messages['notSent']);
        }
        if ($session === null || $userId === null) {
            return self::no($messages['signedOut']);
        }
        if (Editor::editableGame($session, $userId, $shortname) === null) {
            return self::no($messages['notAllowed']);
        }
        try {
            $rows = $session->from('game')->update($values)->eq('shortname', $shortname)->select('shortname')->get();
        } catch (SupabaseException $e) {
            Log::error("{$action}: {$shortname} was not updated", ['error' => $e->getMessage()]);

            return self::no($messages['notSaved']);
        }
        if ($rows === []) {
            return self::no($messages['notAllowed']);
        }
        HubCache::invalidate([HubCache::GAMES]);

        return ['ok' => true, 'message' => $messages['saved']];
    }

    /** The secret key client, or null when this deployment has none. */
    private static function admin(): ?Client
    {
        try {
            return Supabase::admin();
        } catch (SupabaseException) {
            return null;
        }
    }

    private static function orNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /** @return array{ok: false, message: string} */
    private static function no(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
