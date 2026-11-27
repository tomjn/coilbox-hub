<?php

namespace App\Domain\Replays;

use App\Domain\Mirrors\HubKeys;
use App\Domain\Mirrors\UploadToken;
use App\Jobs\CheckReplayTrack;
use App\Support\Bearer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts a replay track upload from Coilbox (#665), as {@see ReplayUploads}
 * does for the replay. Any signed-in user can send one once the replay has a
 * passing analysis. A replay keeps one track per format version, so later
 * senders upload nothing, unless theirs is from a newer recorder (#687).
 */
final class TrackUploads
{
    private const GAME_ID = '/\A[0-9a-f]{32}\z/';

    /** @return array{status: int, body: array<string, mixed>} */
    public static function start(Bearer $auth, string $gameId, string $raw): array
    {
        if (preg_match(self::GAME_ID, $gameId) !== 1) {
            return self::fail(400, 'The game id must be 32 lower case hex characters.', 'bad_request');
        }
        $json = json_decode($raw, true);
        if (! is_array($json) || ($json !== [] && array_is_list($json))) {
            return self::fail(400, 'Send the track as a JSON object.', 'bad_request');
        }
        $version = $json['format_version'] ?? null;
        if (! is_int($version) || $version < 1) {
            return self::fail(400, 'format_version must be the version in the track\'s header, a whole number of at least 1.', 'bad_request');
        }
        $sha256 = $json['sha256'] ?? null;
        if (! is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            return self::fail(400, 'sha256 must be 64 lowercase hex characters.', 'bad_request');
        }
        $size = $json['size'] ?? null;
        if (! is_int($size) || $size < 1) {
            return self::fail(400, 'size must be a whole number of bytes, at least 1.', 'bad_request');
        }
        $recorder = $json['recorder_version'] ?? null;
        if ($recorder !== null && (! is_int($recorder) || $recorder < 1)) {
            return self::fail(400, 'recorder_version must be the version in the track\'s header, a whole number of at least 1.', 'bad_request');
        }
        if (DB::table('replay')->where('game_id', $gameId)->where('state', 'listed')->doesntExist()) {
            return self::fail(404, 'The hub has no listed replay with this game id.', 'not_listed');
        }
        // A mirror names a track by its game id alone, so a second format needs a name of its own first.
        if ($version !== TrackFile::FORMAT) {
            return self::fail(422, 'The hub takes track format '.TrackFile::FORMAT.' only.', 'unknown_format');
        }
        if (DB::table('replay_analysis')->where('game_id', $gameId)->doesntExist()) {
            return self::fail(409, 'Send an analysis of this replay first. A track is taken once one has passed.', 'no_analysis');
        }

        $keys = HubKeys::fromConfig();
        if ($keys === null) {
            return self::fail(503, 'Uploads are not set up on this hub yet.', 'not_configured');
        }

        try {
            // As for replays, two senders racing for a new track can deadlock on MariaDB.
            return DB::transaction(fn () => self::begin($auth->userId, $gameId, $version, $recorder, $sha256, $size, $keys), 3);
        } catch (UniqueConstraintViolationException) {
            return self::fail(409, 'Someone else is sending this track just now. Try again later.', 'uploading');
        }
    }

    /**
     * Moves every track this mirror now holds on to its check, as
     * {@see ReplayUploads::received()} does for replays.
     *
     * @param  list<string>  $held
     */
    public static function received(string $mirrorId, array $held): void
    {
        if ($held === []) {
            return;
        }

        DB::transaction(function () use ($mirrorId, $held) {
            $now = self::now();
            $rows = DB::table('replay_track')->where('state', 'uploading')->where('upload_mirror_id', $mirrorId)
                ->whereIn('sha256', $held)->lockForUpdate()->get(['game_id', 'format_version', 'replacing']);
            foreach ($rows as $row) {
                self::row($row->game_id, (int) $row->format_version, (bool) $row->replacing)->update([
                    'state' => 'checking', 'received_at' => $now, 'reserved_until' => null, 'updated_at' => $now,
                ]);
                CheckReplayTrack::dispatch($row->game_id, (int) $row->format_version)->afterCommit();
            }
        });
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private static function begin(string $user, string $gameId, int $version, ?int $recorder, string $sha256, int $size, HubKeys $keys): array
    {
        $row = self::row($gameId, $version, false)->lockForUpdate()->first();
        if ($row === null || $row->state === 'uploading') {
            return self::reserve($user, $gameId, $version, $sha256, $size, $keys, $row, null);
        }

        // A newer recorder gets more out of the same replay, so its track replaces the listed one once it passes (#687).
        if ($recorder === null || $row->state !== 'listed' || $row->recorder_version === null || $recorder <= (int) $row->recorder_version) {
            return ['status' => 200, 'body' => ['track' => Tracks::answer($row)]];
        }
        $pending = self::row($gameId, $version, true)->lockForUpdate()->first();
        if ($pending !== null && $pending->state !== 'uploading') {
            return ['status' => 200, 'body' => ['track' => Tracks::answer($pending)]];
        }

        return self::reserve($user, $gameId, $version, $sha256, $size, $keys, $pending, $row);
    }

    /**
     * Reserves the upload of a replay's first track, or with `$listed` of one
     * to replace it.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function reserve(string $user, string $gameId, int $version, string $sha256, int $size, HubKeys $keys, ?object $row, ?object $listed): array
    {
        $now = self::now();
        $live = $row !== null && $row->reserved_until !== null && (string) $row->reserved_until >= $now;
        if ($live && $row->uploader !== $user) {
            return ['status' => 409, 'body' => [
                'error' => 'Someone else is sending this track just now. Try again later.',
                'reason' => 'uploading',
                'reserved_until' => CarbonImmutable::parse((string) $row->reserved_until, 'UTC')->getTimestamp(),
            ]];
        }

        $mirror = ReplayUploads::mirror($live ? $row->upload_mirror_id : null, $listed === null ? 'takes_tracks' : 'replaces_tracks');
        if ($mirror === null && $listed !== null) {
            // Every mirror holds the listed file under the same name, and refuses another unless it can replace one.
            return ['status' => 200, 'body' => ['track' => Tracks::answer($listed)]];
        }
        if ($mirror === null) {
            return self::fail(503, 'No mirror takes tracks yet.', 'no_track_mirror');
        }

        $until = CarbonImmutable::now('UTC')->addSeconds((int) config('hub.mirrors.reservation'));
        // The same sender asking again for the same file keeps the upload id, so the mirror resumes what it holds.
        $same = $live && $row->sha256 === $sha256 && (int) $row->size === $size;
        $uploadId = $same ? (string) $row->upload_id : (string) Str::uuid();
        $fields = [
            'state' => 'uploading', 'uploader' => $user, 'sha256' => $sha256, 'size' => $size, 'upload_mirror_id' => $mirror->id,
            'upload_id' => $uploadId, 'reserved_until' => $until->format('Y-m-d H:i:s.u'), 'received_at' => null, 'updated_at' => $now,
        ];

        $replacing = $listed !== null;
        if ($row === null) {
            DB::table('replay_track')->insert($fields + ['game_id' => $gameId, 'format_version' => $version, 'replacing' => $replacing, 'created_at' => $now]);
        } else {
            self::row($gameId, $version, $replacing)->update($fields);
        }
        ReplayListing::holdersChanged($sha256, ...($row === null ? [] : [(string) $row->sha256]));

        $base = rtrim((string) $mirror->base_url, '/');
        $startBy = CarbonImmutable::now('UTC')->getTimestamp() + (int) config('hub.mirrors.token_window');
        $filename = Tracks::filename($gameId);

        return ['status' => $live ? 200 : 201, 'body' => ['upload' => [
            'id' => $uploadId,
            'game_id' => $gameId,
            'format_version' => $version,
            'state' => 'uploading',
            'mirror' => $mirror->id,
            'filename' => $filename,
            'upload_url' => $base.'/upload.php',
            'limits_url' => $base.'/limits.php',
            'token' => UploadToken::issue($mirror->id, $uploadId, $sha256, $size, $filename, 'track', $startBy, $keys->secretKey(), $listed?->sha256),
            'start_by' => $startBy,
            'reserved_until' => $until->getTimestamp(),
        ]]];
    }

    private static function row(string $gameId, int $version, bool $replacing): Builder
    {
        return DB::table('replay_track')->where('game_id', $gameId)->where('format_version', $version)->where('replacing', $replacing);
    }

    private static function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private static function fail(int $status, string $message, string $reason): array
    {
        return ['status' => $status, 'body' => ['error' => $message, 'reason' => $reason]];
    }
}
