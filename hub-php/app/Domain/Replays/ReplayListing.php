<?php

namespace App\Domain\Replays;

use App\Supabase\Local\MirrorFunctions;
use App\Supabase\Local\Values;
use Illuminate\Support\Facades\DB;

/**
 * What the mirror catalogue says about replays (#676). A listed replay is in
 * `replays`, so every mirror copies it. A removed one is in `removed`, so every
 * mirror deletes it. Change either only through here, so mirrors get a new
 * catalogue.
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
            DB::table('replay_removal')->upsert(
                [['sha256' => $replay->sha256, 'game_id' => $gameId, 'reason' => $reason, 'removed_at' => $now]],
                ['sha256'], ['game_id', 'reason', 'removed_at'],
            );
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
     * The catalogue's `removed` entries for replays. A mirror deletes a file
     * whose hash or name matches. A refused match may be sent again under the
     * same name, so the name here is one no mirror file can have. A hash a
     * replay or an archive still holds is left out: anyone can send another
     * file's bytes as a replay, and refusing it must not delete that file.
     *
     * @return list<array{sha256: string, filename: string, reason: string, removed_at: int}>
     */
    public static function removals(): array
    {
        return DB::table('replay_removal as x')
            ->whereNotExists(fn ($q) => $q->from('replay')->whereColumn('replay.sha256', 'x.sha256')->whereIn('replay.state', self::HELD))
            ->whereNotExists(fn ($q) => $q->from('archive')->whereColumn('archive.sha256', 'x.sha256')->whereIn('archive.state', ['uploading', 'listed']))
            ->orderBy('removed_at')->orderBy('sha256')->get()
            ->map(fn ($r) => [
                'sha256' => $r->sha256, 'filename' => $r->game_id.'.sdfz.removed', 'reason' => $r->reason,
                'removed_at' => (int) floor((float) MirrorFunctions::epoch((string) $r->removed_at)),
            ])->all();
    }
}
