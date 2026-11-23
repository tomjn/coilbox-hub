<?php

namespace App\Domain\Points;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Community points for adding to the hub. One row in `community_point` per
 * award, so a repeat of the same contribution adds nothing.
 */
final class CommunityPoints
{
    public const GAME_OWNER = 'game_owner';

    public const ARCHIVE = 'archive';

    public const ITEM = 'item';

    public const PICTURE = 'picture';

    public const UNIT_PICTURE = 'unit_picture';

    public const GAME_FACTS = 'game_facts';

    public const MAP = 'map';

    public const REPORT = 'report';

    public const FIRST_TEXT = 'first_text';

    /** @var array<string, int> */
    public const POINTS = [
        self::GAME_OWNER => 100,
        self::ARCHIVE => 20,
        self::ITEM => 20,
        self::PICTURE => 20,
        self::UNIT_PICTURE => 1,
        self::GAME_FACTS => 5,
        self::MAP => 5,
        self::REPORT => 5,
        self::FIRST_TEXT => 5,
    ];

    /**
     * Does nothing without a user. A failed write is reported and swallowed,
     * because points must never fail the contribution that earned them.
     */
    public static function award(?string $userId, string $kind, string $subject): void
    {
        if ($userId === null) {
            return;
        }
        if (! isset(self::POINTS[$kind])) {
            report(new \InvalidArgumentException("Unknown community point kind: $kind"));

            return;
        }
        try {
            // The first text on a field pays one account, whoever adds it.
            if ($kind === self::FIRST_TEXT && DB::table('community_point')->where('kind', $kind)->where('subject', $subject)->exists()) {
                return;
            }
            DB::table('community_point')->insertOrIgnore([
                'user_id' => $userId,
                'kind' => $kind,
                'subject' => $subject,
                'points' => self::POINTS[$kind],
                'created_at' => now()->format('Y-m-d H:i:s.u'),
            ]);
        } catch (QueryException $e) {
            report($e);
        }
    }

    /**
     * The subject of a first text award. `$factionKey` is null for a game
     * field. A faction key too long to fit the 255 character subject column
     * is replaced by its hash.
     */
    public static function firstTextSubject(string $gameId, ?string $factionKey, string $field): string
    {
        if ($factionKey === null) {
            return "game:{$gameId}:{$field}";
        }
        $subject = "faction:{$gameId}:{$factionKey}:{$field}";

        return strlen($subject) > 255 ? "faction:{$gameId}:".sha1($factionKey).":{$field}" : $subject;
    }

    /** Takes the points back from whoever holds them. Failures are reported and swallowed. */
    public static function revoke(string $kind, string $subject): void
    {
        try {
            DB::table('community_point')->where('kind', $kind)->where('subject', $subject)->delete();
        } catch (QueryException $e) {
            report($e);
        }
    }

    /** Drops every row an account holds when it is deleted. Failures are reported and swallowed. */
    public static function forget(string $userId): void
    {
        try {
            DB::table('community_point')->where('user_id', $userId)->delete();
        } catch (QueryException $e) {
            report($e);
        }
    }

    public static function total(string $userId): int
    {
        return (int) DB::table('community_point')->where('user_id', $userId)->sum('points');
    }
}
