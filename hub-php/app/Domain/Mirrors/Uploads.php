<?php

namespace App\Domain\Mirrors;

use App\Support\Bearer;
use App\Support\Site;
use App\Supabase\Client;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Support\Carbon;

/** Reserves an upload slot on a mirror and issues the token that lets Coilbox use it. */
final class Uploads
{
    /** A cap on the JSON facts in a request. It is not a limit on archive size. */
    public const MAX_BODY_BYTES = 16384;

    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    /** @return array{status: int, body: array<string, mixed>} */
    public static function start(Bearer $auth, string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return self::fail(400, 'The request body must be JSON.', 'bad_request');
        }
        $facts = UploadFacts::parse($decoded);
        if (is_string($facts)) {
            return self::fail(400, $facts, 'bad_request');
        }

        $keys = HubKeys::fromConfig();
        if ($keys === null) {
            return self::fail(503, 'Uploads are not set up on this hub yet.', 'not_configured');
        }

        try {
            $admin = Supabase::admin();
            $result = $admin->rpc('begin_archive_upload', [
                'p_user' => $auth->userId,
                'p_is_moderator' => self::isModerator($auth->client),
                'p_facts' => $facts,
                'p_reservation_seconds' => (int) config('hub.mirrors.reservation'),
            ]);

            $outcome = is_array($result) ? ($result['outcome'] ?? null) : null;
            if ($outcome === 'created' || $outcome === 'reissued') {
                return self::issued($result, $facts, $keys, $outcome === 'created' ? 201 : 200);
            }
            if ($outcome === 'refused') {
                return self::refused($result, $facts, $admin);
            }
        } catch (SupabaseException) {
            // Falls through to the same answer as an outcome the hub does not know.
        }

        return self::fail(503, 'Uploads are unavailable just now.', 'unavailable');
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public static function status(Bearer $auth, string $id): array
    {
        if (preg_match(self::UUID, $id) !== 1) {
            return ['status' => 404, 'body' => ['error' => 'No such upload.']];
        }

        try {
            $row = Supabase::admin()->rpc('archive_upload_status', [
                'p_user' => $auth->userId,
                'p_id' => strtolower($id),
                'p_reservation_seconds' => (int) config('hub.mirrors.reservation'),
            ]);
        } catch (SupabaseException) {
            return ['status' => 503, 'body' => ['error' => 'Uploads are unavailable just now.']];
        }

        if (! is_array($row)) {
            return ['status' => 404, 'body' => ['error' => 'No such upload.']];
        }

        return ['status' => 200, 'body' => ['upload' => [
            'id' => $row['id'],
            'state' => $row['state'],
            'filename' => $row['filename'],
            'kind' => $row['kind'],
            'reserved_until' => $row['reserved_until'],
        ]]];
    }

    /** The second factor rule lives in the database function, so it is asked as the caller. */
    private static function isModerator(Client $client): bool
    {
        try {
            return $client->rpc('is_moderator') === true;
        } catch (SupabaseException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $facts
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function issued(array $result, array $facts, HubKeys $keys, int $status): array
    {
        $base = rtrim((string) $result['base_url'], '/');
        $startBy = Carbon::now()->getTimestamp() + (int) config('hub.mirrors.token_window');
        $token = UploadToken::issue(
            (string) $result['mirror_id'],
            (string) $result['id'],
            $facts['sha256'],
            $facts['size'],
            $facts['filename'],
            $facts['kind'],
            $startBy,
            $keys->secretKey(),
        );

        return ['status' => $status, 'body' => ['upload' => [
            'id' => $result['id'],
            'state' => 'uploading',
            'mirror' => $result['mirror_id'],
            'upload_url' => $base.'/upload.php',
            'limits_url' => $base.'/limits.php',
            'token' => $token,
            'start_by' => $startBy,
            'reserved_until' => $result['reserved_until'],
        ]]];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $facts
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function refused(array $result, array $facts, Client $admin): array
    {
        $reason = $result['reason'] ?? null;

        if ($reason === 'unknown_game') {
            return self::fail(422, 'The hub does not know a game called "'.$facts['shortname'].'".', 'unknown_game');
        }
        if ($reason === 'unknown_map') {
            return self::fail(422, 'The hub has no facts for the map "'.$facts['internal_name'].'". Send the map to the hub first.', 'unknown_map');
        }
        if ($reason === 'not_owner') {
            return self::fail(403, 'You do not own the game "'.$facts['shortname'].'".', 'not_owner');
        }
        if ($reason === 'no_mirror') {
            return self::fail(503, 'No mirror can take an upload just now.', 'no_mirror');
        }
        if ($reason === 'duplicate') {
            $existing = is_array($result['existing'] ?? null) ? $result['existing'] : [];

            return ['status' => 409, 'body' => [
                'error' => 'The hub already has this archive.',
                'reason' => 'duplicate',
                'duplicate' => [
                    'check' => $result['check'] ?? null,
                    'filename' => $existing['filename'] ?? null,
                    'kind' => $existing['kind'] ?? null,
                    'state' => $existing['state'] ?? null,
                    'url' => self::pageUrl($existing, $admin),
                ],
            ]];
        }

        return self::fail(503, 'Uploads are unavailable just now.', 'unavailable');
    }

    /**
     * The hub page of a listed archive, or null when it is not listed or has no page.
     *
     * @param  array<string, mixed>  $existing
     */
    private static function pageUrl(array $existing, Client $admin): ?string
    {
        if (($existing['state'] ?? null) !== 'listed') {
            return null;
        }

        if (($existing['kind'] ?? null) === 'game') {
            $shortname = $existing['shortname'] ?? null;

            return is_string($shortname) ? Site::absolute('/games/'.rawurlencode($shortname)) : null;
        }

        $name = $existing['internal_name'] ?? null;
        if (! is_string($name)) {
            return null;
        }
        $map = $admin->from('map')->select('slug')->eq('map_name', $name)->maybeSingle();
        $slug = $map['slug'] ?? null;

        return is_string($slug) && $slug !== '' ? Site::absolute('/maps/'.rawurlencode($slug)) : null;
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private static function fail(int $status, string $message, string $reason): array
    {
        return ['status' => $status, 'body' => ['error' => $message, 'reason' => $reason]];
    }
}
