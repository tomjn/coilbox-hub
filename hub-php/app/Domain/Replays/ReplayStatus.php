<?php

namespace App\Domain\Replays;

use App\Support\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What became of the replay a signed in user sent (#694). Only reads, so
 * asking never reserves a match or keeps a reservation alive.
 */
final class ReplayStatus
{
    /**
     * What a sender reads for each code in `replay_refusal`. The row's detail is
     * for moderators. A later sender gets the refusal of a file and summary they
     * did not send, so none of these says "your".
     */
    private const REFUSALS = [
        'wrong_file' => 'The file that reached the hub was not the one Coilbox described. You can send it again.',
        'unreadable' => 'The hub could not read the replay file. It may be damaged or too large.',
        'wrong_game_id' => 'The replay file belongs to a different match.',
        'no_game_over' => 'The replay stops before the match ended. The hub only takes finished matches.',
        'no_end_of_game' => 'The replay has no end of match results. The hub only takes finished matches.',
        'summary_mismatch' => 'What was sent about the match does not agree with the replay file.',
        'sender_not_in_replay' => 'The name this match was sent under did not play or watch it.',
        'check_failed' => 'The hub could not finish checking the replay. You can send it again.',
    ];

    /** @return array{status: int, body: array<string, mixed>} */
    public static function of(string $user, string $gameId): array
    {
        // One query, so a check finishing meanwhile cannot split the row from its sender.
        $row = DB::table('replay as r')
            ->leftJoin('replay_sender as s', fn ($join) => $join->on('s.game_id', '=', 'r.game_id')->where('s.user_id', $user))
            ->where('r.game_id', $gameId)
            ->first(['r.state', 'r.reserved_until', 's.user_id as sender']);

        if ($row !== null && $row->sender !== null) {
            $until = $row->state === 'uploading' && $row->reserved_until !== null
                ? CarbonImmutable::parse($row->reserved_until, 'UTC') : null;

            return self::answer($gameId,
                $until?->isPast() ? 'lapsed' : $row->state,
                reservedUntil: $until?->getTimestamp(),
            );
        }

        $refusal = DB::table('replay_refusal as f')->where('f.game_id', $gameId)
            ->where(fn ($q) => $q->where('f.uploader', $user)->orWhereExists(fn ($e) => $e->from('replay_refusal_sender as s')
                ->whereColumn('s.refusal_id', 'f.id')->where('s.user_id', $user)))
            ->orderByDesc('f.refused_at')->orderByDesc('f.id')->first(['f.reason', 'f.refused_at']);
        if ($refusal !== null) {
            return self::answer($gameId, 'refused', refusal: [
                'reason' => $refusal->reason,
                'message' => self::REFUSALS[$refusal->reason] ?? 'The hub refused this replay.',
                'refused_at' => CarbonImmutable::parse($refusal->refused_at, 'UTC')->getTimestamp(),
            ]);
        }

        // A listed match is public. This also covers a later sender the check dropped for their name.
        if ($row?->state === 'listed') {
            return self::answer($gameId, 'listed');
        }

        return ['status' => 404, 'body' => ['error' => 'You have not sent the hub this match.', 'reason' => 'not_sent']];
    }

    /**
     * @param  array{reason: string, message: string, refused_at: int}|null  $refusal
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function answer(string $gameId, string $state, ?int $reservedUntil = null, ?array $refusal = null): array
    {
        return ['status' => 200, 'body' => ['replay' => [
            'game_id' => $gameId,
            'state' => $state,
            'url' => $state === 'listed' ? Site::absolute(ReplayUploads::pagePath($gameId)) : null,
            'reserved_until' => $reservedUntil,
            'refusal' => $refusal,
        ]]];
    }
}
