<?php

namespace App\Domain\Replays;

use Illuminate\Support\Facades\DB;

/** The tracks the hub holds (#665), as the API and the replay page (#667) show them. */
final class Tracks
{
    /** A track's name on every mirror (PROTOCOL.md section 2). */
    public static function filename(string $gameId): string
    {
        return $gameId.'.cbtrack';
    }

    /**
     * A replay's listed tracks, newest format first.
     *
     * @return list<array<string, mixed>> each as {@see answer()}
     */
    public static function listed(string $gameId): array
    {
        return DB::table('replay_track')->where('game_id', $gameId)->where('state', 'listed')->orderByDesc('format_version')->get()
            ->map(fn ($row) => self::answer($row))->all();
    }

    /**
     * @param  object  $row  a `replay_track` row
     * @return array{game_id: string, format_version: int, state: string, recorder_version: ?int, size: int, sha256: string, url: ?string}
     */
    public static function answer(object $row): array
    {
        return [
            'game_id' => $row->game_id,
            'format_version' => (int) $row->format_version,
            'state' => $row->state,
            'recorder_version' => $row->recorder_version === null ? null : (int) $row->recorder_version,
            'size' => (int) $row->size,
            'sha256' => $row->sha256,
            'url' => self::url($row),
        ];
    }

    /**
     * Where to download a listed track. The mirror it was uploaded to is the
     * one the hub knows holds it, so this is null while that mirror is off or
     * unhealthy, and until the track is listed.
     */
    public static function url(object $row): ?string
    {
        if ($row->state !== 'listed') {
            return null;
        }
        $base = DB::table('mirror')->where('id', $row->upload_mirror_id)->where('enabled', true)->where('healthy', true)->value('base_url');

        return $base === null ? null : rtrim((string) $base, '/').'/tracks/'.self::filename($row->game_id);
    }
}
