<?php

namespace App\Domain\Replays;

use App\Domain\Mirrors\HubKeys;
use App\Domain\Mirrors\Uploads;
use App\Domain\Mirrors\UploadToken;
use App\Jobs\CheckReplay;
use App\Support\Bearer;
use App\Support\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts a replay upload from Coilbox (#662). A match is stored once, keyed by
 * the game id in its header. A later sender is recorded and uploads nothing.
 * The first gets an upload token for a mirror that takes replays, as archives
 * do in {@see Uploads}.
 */
final class ReplayUploads
{
    private const GAME_ID = '/\A[0-9a-f]{32}\z/';

    /** @return array{status: int, body: array<string, mixed>} */
    public static function start(Bearer $auth, string $raw): array
    {
        $json = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return self::fail(400, 'The request body must be JSON.', 'bad_request');
        }
        $request = self::parse($json);
        if (is_string($request)) {
            return self::fail(400, $request, 'bad_request');
        }
        // Only the summary can be checked now. #663 checks the name against the file.
        if (! ReplaySummary::names($request['summary'], $request['name'])) {
            return self::fail(422, 'Nobody called "'.$request['name'].'" played or watched this match.', 'not_in_replay');
        }

        $keys = HubKeys::fromConfig();
        if ($keys === null) {
            return self::fail(503, 'Uploads are not set up on this hub yet.', 'not_configured');
        }

        try {
            // Two senders racing for a new match can deadlock on MariaDB. The retry then finds the winner's row.
            return DB::transaction(fn () => self::begin($auth->userId, $request, $keys), 3);
        } catch (UniqueConstraintViolationException) {
            // Another sender reserved the match between the check and the insert.
            return self::fail(409, 'Someone else is sending this match just now. Try again later.', 'uploading');
        }
    }

    /**
     * Moves every replay this mirror now holds on to the check in #663. Any
     * report links an upload by hash, as for archives, because an upload
     * report can be lost and the next sync report carries the file instead.
     * A lapsed reservation nobody has taken since still counts.
     *
     * @param  list<string>  $held
     * @return list<string> the game ids moved on
     */
    public static function received(string $mirrorId, array $held): array
    {
        if ($held === []) {
            return [];
        }

        return DB::transaction(function () use ($mirrorId, $held) {
            $now = self::now();
            $gameIds = DB::table('replay')->where('state', 'uploading')->where('upload_mirror_id', $mirrorId)
                ->whereIn('sha256', $held)->lockForUpdate()->pluck('game_id')->all();
            if ($gameIds === []) {
                return [];
            }
            DB::table('replay')->whereIn('game_id', $gameIds)->update([
                'state' => 'checking', 'received_at' => $now, 'reserved_until' => null, 'updated_at' => $now,
            ]);
            foreach ($gameIds as $gameId) {
                CheckReplay::dispatch($gameId)->afterCommit();
            }

            return $gameIds;
        });
    }

    /** The hub page of a listed replay. #667 serves it. */
    public static function pagePath(string $gameId): string
    {
        return '/replays/'.$gameId;
    }

    /**
     * @param  array{game_id: string, sha256: string, size: int, name: string, summary: array<string, mixed>}  $request
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function begin(string $user, array $request, HubKeys $keys): array
    {
        $now = self::now();
        $gameId = $request['game_id'];
        $row = DB::table('replay')->where('game_id', $gameId)->lockForUpdate()->first();

        // A moderator took this match, or this file under another game id, down (#671).
        if ($row?->state === 'removed' || DB::table('replay')->where('sha256', $request['sha256'])->where('state', 'removed')->exists()) {
            return self::fail(410, 'A moderator took this match down from the hub, so it cannot be sent again.', 'removed');
        }

        if ($row !== null && $row->state !== 'uploading') {
            DB::table('replay_sender')->insertOrIgnore([
                'game_id' => $gameId, 'user_id' => $user, 'name' => $request['name'], 'sent_at' => $now,
            ]);

            return ['status' => 200, 'body' => ['replay' => [
                'game_id' => $gameId,
                'state' => $row->state,
                'url' => $row->state === 'listed' ? Site::absolute(self::pagePath($gameId)) : null,
            ]]];
        }

        $live = $row !== null && $row->reserved_until !== null && (string) $row->reserved_until >= $now;
        if ($live && $row->uploader !== $user) {
            return ['status' => 409, 'body' => [
                'error' => 'Someone else is sending this match just now. Try again later.',
                'reason' => 'uploading',
                'reserved_until' => self::epoch((string) $row->reserved_until),
            ]];
        }

        $mirror = self::mirror($live ? $row->upload_mirror_id : null);
        if ($mirror === null) {
            return self::fail(503, 'No mirror takes replays yet.', 'no_replay_mirror');
        }

        $until = CarbonImmutable::now('UTC')->addSeconds((int) config('hub.mirrors.reservation'));
        $fields = [
            'state' => 'uploading', 'uploader' => $user, 'sha256' => $request['sha256'], 'size' => $request['size'],
            'upload_mirror_id' => $mirror->id, 'summary' => json_encode($request['summary'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            'reserved_until' => $until->format('Y-m-d H:i:s.u'), 'received_at' => null, 'updated_at' => $now,
        ];
        // The same sender asking again for the same file keeps the upload id, so the mirror resumes what it holds.
        $same = $live && $row->sha256 === $request['sha256'] && (int) $row->size === $request['size'];
        $uploadId = $same ? (string) $row->upload_id : (string) Str::uuid();

        if ($row === null) {
            DB::table('replay')->insert($fields + ['game_id' => $gameId, 'upload_id' => $uploadId, 'created_at' => $now]);
        } else {
            DB::table('replay')->where('game_id', $gameId)->update($fields + ['upload_id' => $uploadId]);
            // A lapsed reservation was only ever its uploader's.
            DB::table('replay_sender')->where('game_id', $gameId)->where('user_id', '<>', $user)->delete();
        }
        ReplayListing::holdersChanged($request['sha256'], ...($row === null ? [] : [(string) $row->sha256]));
        DB::table('replay_sender')->upsert(
            [['game_id' => $gameId, 'user_id' => $user, 'name' => $request['name'], 'sent_at' => $now]],
            ['game_id', 'user_id'], ['name', 'sent_at'],
        );

        $base = rtrim((string) $mirror->base_url, '/');
        $startBy = CarbonImmutable::now('UTC')->getTimestamp() + (int) config('hub.mirrors.token_window');
        $filename = $gameId.'.sdfz';

        return ['status' => $live ? 200 : 201, 'body' => ['upload' => [
            'id' => $uploadId,
            'game_id' => $gameId,
            'state' => 'uploading',
            'mirror' => $mirror->id,
            'filename' => $filename,
            'upload_url' => $base.'/upload.php',
            'limits_url' => $base.'/limits.php',
            'token' => UploadToken::issue($mirror->id, $uploadId, $request['sha256'], $request['size'], $filename, 'replay', $startBy, $keys->secretKey()),
            'start_by' => $startBy,
            'reserved_until' => $until->getTimestamp(),
        ]]];
    }

    /**
     * An enabled, healthy mirror that takes this kind: the one asked for when
     * it qualifies, otherwise any at random.
     *
     * @param  string  $takes  the mirror's column for the kind, such as `takes_replays`
     */
    public static function mirror(?string $id, string $takes = 'takes_replays'): ?object
    {
        $query = DB::table('mirror')->where('enabled', true)->where('healthy', true)->where($takes, true);
        if ($id !== null) {
            $mirror = (clone $query)->where('id', $id)->first();
            if ($mirror !== null) {
                return $mirror;
            }
        }
        $mirrors = $query->get()->all();

        return $mirrors === [] ? null : $mirrors[array_rand($mirrors)];
    }

    /** @return array{game_id: string, sha256: string, size: int, name: string, summary: array<string, mixed>}|string */
    private static function parse(mixed $json): array|string
    {
        if (! is_array($json) || ($json !== [] && array_is_list($json))) {
            return 'Send the replay as a JSON object.';
        }
        $gameId = $json['game_id'] ?? null;
        if (! is_string($gameId) || preg_match(self::GAME_ID, $gameId) !== 1) {
            return 'game_id must be the 16 byte game id from the replay header, as 32 lower case hex characters.';
        }
        $sha256 = $json['sha256'] ?? null;
        if (! is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            return 'sha256 must be 64 lowercase hex characters.';
        }
        $size = $json['size'] ?? null;
        if (! is_int($size) || $size < 1) {
            return 'size must be a whole number of bytes, at least 1.';
        }
        $name = $json['name'] ?? null;
        if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 255) {
            return 'name must be the name you were in the battle under, 1 to 255 characters.';
        }
        $summary = ReplaySummary::parse($json['summary'] ?? null);
        if (is_string($summary)) {
            return $summary;
        }

        return ['game_id' => $gameId, 'sha256' => $sha256, 'size' => $size, 'name' => $name, 'summary' => $summary];
    }

    private static function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
    }

    private static function epoch(string $stored): int
    {
        return CarbonImmutable::parse($stored, 'UTC')->getTimestamp();
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private static function fail(int $status, string $message, string $reason): array
    {
        return ['status' => $status, 'body' => ['error' => $message, 'reason' => $reason]];
    }
}
