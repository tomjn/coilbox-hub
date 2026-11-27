<?php

namespace App\Domain\Replays;

use App\Supabase\Local\MirrorFunctions;
use App\Supabase\Local\Values;
use Illuminate\Support\Facades\DB;

/**
 * What the mirror catalogue says about replays (#676) and their tracks (#665).
 * A listed one is in `replays` or `tracks`, so every mirror copies it. A
 * removed one is in `removed`, so every mirror deletes it. Change either only
 * through here, so mirrors get a new catalogue.
 */
final class ReplayListing
{
    /** The states whose file a mirror must be allowed to hold. */
    private const HELD = ['uploading', 'checking', 'listed'];

    /** Lists a replay that passed its check. False when it is not being checked. */
    public static function publish(string $gameId): bool
    {
        return DB::transaction(function () use ($gameId) {
            $listed = DB::table('replay')->where('game_id', $gameId)->where('state', 'checking')
                ->update(['state' => 'listed', 'updated_at' => Values::now()]);
            if ($listed === 1) {
                MapTotals::refresh($gameId);
            }
            MirrorFunctions::bump($listed);

            return $listed === 1;
        });
    }

    /**
     * Moves a checked or listed replay to `removed` and records its file for
     * the mirrors to delete. The record stays if the replay's row is deleted
     * afterwards to free the game id. False when there is no such replay.
     *
     * @param  string  $reason  A short code anyone can read in the catalogue.
     */
    public static function remove(string $gameId, string $reason): bool
    {
        return DB::transaction(function () use ($gameId, $reason) {
            $replay = DB::table('replay')->where('game_id', $gameId)->whereIn('state', ['checking', 'listed'])->lockForUpdate()->first();
            if ($replay === null) {
                return false;
            }
            $now = Values::now();
            DB::table('replay')->where('game_id', $gameId)->update(['state' => 'removed', 'updated_at' => $now]);
            // Takes its share back out of the map totals.
            MapTotals::refresh($gameId);
            self::record($replay->sha256, $gameId, 'replay', $reason, $now);
            // A replay's tracks go with it, including one still on its way.
            $tracks = DB::table('replay_track')->where('game_id', $gameId)->where('state', '!=', 'removed');
            foreach ((clone $tracks)->lockForUpdate()->pluck('sha256') as $sha256) {
                self::record($sha256, $gameId, 'track', $reason, $now);
            }
            $tracks->update(['state' => 'removed', 'updated_at' => $now]);
            MirrorFunctions::bump();

            return true;
        });
    }

    /** Lists a track that passed its check. False when it is not being checked. */
    public static function publishTrack(string $gameId, int $formatVersion, int $recorderVersion): bool
    {
        return DB::transaction(function () use ($gameId, $formatVersion, $recorderVersion) {
            $now = Values::now();
            $listed = DB::table('replay_track')->where('game_id', $gameId)->where('format_version', $formatVersion)->where('state', 'checking')
                ->update(['state' => 'listed', 'recorder_version' => $recorderVersion, 'checked_at' => $now, 'updated_at' => $now]);
            MirrorFunctions::bump($listed);

            return $listed === 1;
        });
    }

    /**
     * Takes away a track that failed its check, so another can be sent, and
     * records its file for the mirrors to delete. False when it is not being
     * checked.
     */
    public static function refuseTrack(string $gameId, int $formatVersion): bool
    {
        return DB::transaction(function () use ($gameId, $formatVersion) {
            $query = DB::table('replay_track')->where('game_id', $gameId)->where('format_version', $formatVersion)->where('state', 'checking');
            $track = (clone $query)->lockForUpdate()->first();
            if ($track === null) {
                return false;
            }
            $query->delete();
            self::record($track->sha256, $gameId, 'track', 'refused', Values::now());
            MirrorFunctions::bump();

            return true;
        });
    }

    /**
     * Call when an upload takes or gives up a hash. The catalogue leaves out a
     * removal while something holds its file, so it has to be built again.
     */
    public static function holdersChanged(string ...$sha256): void
    {
        if (DB::table('replay_removal')->whereIn('sha256', $sha256)->exists()) {
            MirrorFunctions::bump();
        }
    }

    /**
     * The catalogue's `replays` entries.
     *
     * @return list<array{filename: string, sha256: string, size: int}>
     */
    public static function listed(): array
    {
        return DB::table('replay')->where('state', 'listed')->orderBy('game_id')->get(['game_id', 'sha256', 'size'])
            ->map(fn ($r) => ['filename' => $r->game_id.'.sdfz', 'sha256' => $r->sha256, 'size' => (int) $r->size])->all();
    }

    /**
     * The catalogue's `tracks` entries.
     *
     * @return list<array{filename: string, sha256: string, size: int}>
     */
    public static function listedTracks(): array
    {
        return DB::table('replay_track')->where('state', 'listed')->orderBy('game_id')->orderBy('format_version')->get(['game_id', 'sha256', 'size'])
            ->map(fn ($t) => ['filename' => Tracks::filename($t->game_id), 'sha256' => $t->sha256, 'size' => (int) $t->size])->all();
    }

    /**
     * The catalogue's `removed` entries for replays and tracks. A mirror
     * deletes a file whose hash or name matches. A refused one may be sent again
     * under the same name, so the name here is one no mirror file can have. A
     * hash a replay, a track or an archive still holds is left out: anyone can
     * send another file's bytes as theirs, and refusing it must not delete that
     * file.
     *
     * @return list<array{sha256: string, filename: string, reason: string, removed_at: int}>
     */
    public static function removals(): array
    {
        return DB::table('replay_removal as x')
            ->whereNotExists(fn ($q) => $q->from('replay')->whereColumn('replay.sha256', 'x.sha256')->whereIn('replay.state', self::HELD))
            ->whereNotExists(fn ($q) => $q->from('replay_track')->whereColumn('replay_track.sha256', 'x.sha256')->whereIn('replay_track.state', self::HELD))
            ->whereNotExists(fn ($q) => $q->from('archive')->whereColumn('archive.sha256', 'x.sha256')->whereIn('archive.state', ['uploading', 'listed']))
            ->orderBy('removed_at')->orderBy('sha256')->get()
            ->map(fn ($r) => [
                'sha256' => $r->sha256, 'filename' => ($r->kind === 'track' ? Tracks::filename($r->game_id) : $r->game_id.'.sdfz').'.removed',
                'reason' => $r->reason, 'removed_at' => (int) floor((float) MirrorFunctions::epoch((string) $r->removed_at)),
            ])->all();
    }

    private static function record(string $sha256, string $gameId, string $kind, string $reason, string $now): void
    {
        DB::table('replay_removal')->upsert(
            [['sha256' => $sha256, 'game_id' => $gameId, 'kind' => $kind, 'reason' => $reason, 'removed_at' => $now]],
            ['sha256'], ['game_id', 'kind', 'reason', 'removed_at'],
        );
    }
}
