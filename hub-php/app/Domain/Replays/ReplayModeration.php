<?php

namespace App\Domain\Replays;

use App\Domain\Maps\Js;
use App\Supabase\Client;
use App\Supabase\Local\Values;
use App\Supabase\SupabaseException;
use Illuminate\Support\Facades\DB;

/**
 * Reporting a shared replay, and what moderators do about it (#671): take the
 * replay down, or hide its chat and leave the rest up.
 */
final class ReplayModeration
{
    /** The reasons a moderator picks from. The catalogue sends the code to every mirror. */
    public const REASONS = [
        'privacy' => 'Somebody in the match asked for it to come down',
        'harmful' => 'Harmful or abusive',
        'other' => 'Something else',
    ];

    public const MESSAGES = [
        'sent' => 'Reported. Thank you. A moderator will look at it.',
        'signedOut' => 'Sign in to report a replay.',
        'noReason' => 'Say what is wrong, in a sentence or two.',
        'gone' => 'This replay is no longer on the hub.',
        'notSaved' => 'That could not be sent. Try again in a few minutes.',
    ];

    /**
     * Files a report on a listed replay. Only a signed in account may, so a
     * player asking for a takedown can be answered and nobody can flood the
     * queue anonymously. An open report on the replay already holds it, and a
     * second is dropped without an error, as for items and maps.
     *
     * @return array{ok: bool, message: string}
     */
    public static function report(?Client $supabase, ?string $reporterId, string $gameId, mixed $reason): array
    {
        if ($supabase === null || $reporterId === null) {
            return ['ok' => false, 'message' => self::MESSAGES['signedOut']];
        }
        $reason = Js::trim(is_string($reason) ? $reason : '');
        if ($reason === '') {
            return ['ok' => false, 'message' => self::MESSAGES['noReason']];
        }
        if (DB::table('replay')->where('game_id', $gameId)->where('state', 'listed')->doesntExist()) {
            return ['ok' => false, 'message' => self::MESSAGES['gone']];
        }

        try {
            $supabase->from('report')->insert([
                'replay_id' => $gameId,
                'reason' => Js::slice($reason, 1000),
                'reporter_id' => $reporterId,
            ])->execute();
        } catch (SupabaseException) {
            return ['ok' => false, 'message' => self::MESSAGES['notSaved']];
        }

        return ['ok' => true, 'message' => self::MESSAGES['sent']];
    }

    /**
     * Takes a replay down. {@see ReplayListing::remove} lists its file and
     * tracks as removed and takes it out of the map and game totals. The row
     * stays in state `removed` with its senders, which blocks the game id and
     * the file's hash. Everything the check worked out from the file goes:
     * players, teams, chat, analyses and what Coilbox sent about the match.
     * False when there is no checked or listed replay with this id.
     */
    public static function remove(string $gameId, string $reason, string $moderatorId): bool
    {
        if (! isset(self::REASONS[$reason])) {
            return false;
        }

        return DB::transaction(function () use ($gameId, $reason, $moderatorId) {
            if (! ReplayListing::remove($gameId, $reason)) {
                return false;
            }
            DB::table('replay_analysis')->where('game_id', $gameId)->delete();
            DB::table('replay_analysis_submission')->where('game_id', $gameId)->delete();
            DB::table('replay_chat')->where('game_id', $gameId)->delete();
            DB::table('replay_player')->where('game_id', $gameId)->delete();
            DB::table('replay_team')->where('game_id', $gameId)->delete();
            DB::table('replay')->where('game_id', $gameId)->update([
                'summary' => '{}', 'events' => null, 'extra' => null, 'removed_by' => $moderatorId,
            ]);

            return true;
        });
    }

    /**
     * What the report queue shows of each reported replay, by game id.
     *
     * @param  list<string>  $gameIds
     * @return array<string, array{state: string, map: ?string, game: ?string, started_at: ?string}>
     */
    public static function reported(array $gameIds): array
    {
        if ($gameIds === []) {
            return [];
        }

        return DB::table('replay')->whereIn('game_id', $gameIds)->get(['game_id', 'state', 'map', 'game', 'started_at'])
            ->mapWithKeys(fn ($r) => [$r->game_id => [
                'state' => $r->state, 'map' => $r->map, 'game' => $r->game,
                'started_at' => $r->started_at === null ? null : str_replace(' ', 'T', substr((string) $r->started_at, 0, 19)).'Z',
            ]])->all();
    }

    /**
     * Hides one chat message, or all of a replay's chat when `$seq` is null.
     * Answers how many messages were newly hidden.
     */
    public static function hideChat(string $gameId, ?int $seq, string $moderatorId): int
    {
        $query = DB::table('replay_chat')->where('game_id', $gameId)->whereNull('hidden_at');
        if ($seq !== null) {
            $query->where('seq', $seq);
        }

        return $query->update(['hidden_at' => Values::now(), 'hidden_by' => $moderatorId]);
    }
}
