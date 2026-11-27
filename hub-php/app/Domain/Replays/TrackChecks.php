<?php

namespace App\Domain\Replays;

use App\Supabase\Local\Values;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Checks an uploaded track against its replay (#665): the game id, the length
 * and the teams in its header and info chunk. A track that passes is listed.
 * One that fails is removed from the mirrors, another sender can take its
 * place, and the reason is kept in `replay_track_refusal`.
 */
final class TrackChecks
{
    /** `GAME_SPEED` in the engine. */
    private const FRAMES_PER_SECOND = 30;

    /**
     * Checks one track. Answers `listed`, the refusal's reason, or null when
     * the track is not waiting for a check.
     *
     * @throws RuntimeException when the mirror cannot give the file, so the job tries again
     */
    public static function check(string $gameId, int $version): ?string
    {
        $row = self::waiting($gameId, $version)->first();
        if ($row === null) {
            return null;
        }
        $path = tempnam(sys_get_temp_dir(), 'track');
        try {
            self::fetch($row, $path);
            clearstatcache(true, $path);
            if (filesize($path) !== (int) $row->size || hash_file('sha256', $path) !== $row->sha256) {
                return self::refuse($row, 'wrong_file', 'The mirror gave a file of '.filesize($path).' bytes that is not the one reported.');
            }
            $track = TrackFile::read($path);
        } finally {
            @unlink($path);
        }
        if (is_array($track)) {
            return self::refuse($row, ...$track);
        }
        $difference = self::difference($track, $row);
        if ($difference !== null) {
            return self::refuse($row, ...$difference);
        }

        return ReplayListing::publishTrack($gameId, $version, $track->recorderVersion) ? 'listed' : null;
    }

    /** Refuses a track whose check failed on every try. */
    public static function failed(string $gameId, int $version, string $why): ?string
    {
        $row = self::waiting($gameId, $version)->first();

        return $row === null ? null : self::refuse($row, 'check_failed', 'The check did not finish: '.$why);
    }

    /** @return array{0: string, 1: string}|null the reason and detail */
    private static function difference(TrackFile $track, object $row): ?array
    {
        if ($track->gameId !== $row->game_id) {
            return ['wrong_game_id', "The track was recorded from game {$track->gameId}."];
        }
        $duration = (int) DB::table('replay')->where('game_id', $row->game_id)->value('duration');
        $frames = $track->lastFrame - $track->firstFrame;
        // The recorder writes one more block at game over, which can be a frame past the one the engine timed the match at.
        if ($track->framesPerSecond !== self::FRAMES_PER_SECOND
            || (intdiv($frames, self::FRAMES_PER_SECOND) !== $duration && intdiv($frames - 1, self::FRAMES_PER_SECOND) !== $duration)) {
            return ['length_mismatch', "The track has {$frames} frames at {$track->framesPerSecond} a second, and the replay lasted {$duration} seconds."];
        }

        $teams = DB::table('replay_team')->where('game_id', $row->game_id)->orderBy('team')->get(['team', 'ally_team', 'leader_name']);
        $extra = $track->teams;
        foreach ($teams as $team) {
            $id = (int) $team->team;
            $theirs = $extra[$id] ?? null;
            if ($theirs === null) {
                return ['team_mismatch', "The track has no team {$id}."];
            }
            if ($team->ally_team !== null && $theirs['ally_team'] !== (int) $team->ally_team) {
                return ['team_mismatch', "Team {$id} is on ally team {$theirs['ally_team']} in the track and {$team->ally_team} in the replay."];
            }
            // Null for a replay checked before the hub kept leader names.
            $leader = mb_substr(mb_scrub($theirs['leader'], 'UTF-8'), 0, 255);
            if ($team->leader_name !== null && $leader !== $team->leader_name) {
                return ['team_mismatch', "Team {$id}'s leader is \"{$leader}\" in the track and \"{$team->leader_name}\" in the replay."];
            }
            unset($extra[$id]);
        }
        // The engine adds Gaia, the team of the map itself, after the start script's teams. It has no leader.
        $gaia = (int) $teams->max('team') + 1;
        foreach ($extra as $id => $team) {
            if ($id !== $gaia || $team['leader'] !== '') {
                return ['team_mismatch', "The track has a team {$id} that the replay does not."];
            }
        }

        return null;
    }

    /** Streams the file to disk, since a track's size has no limit of the hub's. */
    private static function fetch(object $row, string $path): void
    {
        $base = DB::table('mirror')->where('id', $row->upload_mirror_id)->value('base_url');
        if ($base === null) {
            throw new RuntimeException("The mirror {$row->upload_mirror_id} is not in the hub's list.");
        }
        $size = (int) $row->size;
        $response = Http::timeout(ReplayChecks::FETCH_TIMEOUT)->sink($path)->withOptions([
            // Stop reading at the size the upload gave, so a wrong file cannot fill the disk.
            'progress' => function ($total, $downloaded) use ($size) {
                if ($total > $size || $downloaded > $size) {
                    throw new RuntimeException('The mirror is sending more than the track\'s '.$size.' bytes.');
                }
            },
        ])->get(rtrim((string) $base, '/').'/tracks/'.Tracks::filename($row->game_id));
        if (! $response->successful()) {
            throw new RuntimeException("The mirror answered {$response->status()} for the track file.");
        }
    }

    /** @return string the reason */
    private static function refuse(object $row, string $reason, string $detail): string
    {
        DB::transaction(function () use ($row, $reason, $detail) {
            if (! ReplayListing::refuseTrack($row->game_id, (int) $row->format_version)) {
                return;
            }
            DB::table('replay_track_refusal')->insert([
                'game_id' => $row->game_id, 'format_version' => $row->format_version, 'sha256' => $row->sha256, 'uploader' => $row->uploader,
                'reason' => $reason, 'detail' => mb_substr($detail, 0, 2000), 'refused_at' => Values::now(),
            ]);
        });

        return $reason;
    }

    private static function waiting(string $gameId, int $version): Builder
    {
        return DB::table('replay_track')->where('game_id', $gameId)->where('format_version', $version)->where('state', 'checking');
    }
}
