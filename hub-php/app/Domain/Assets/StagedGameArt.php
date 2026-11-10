<?php

namespace App\Domain\Assets;

use App\Domain\Games\GameArt;
use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * The bytes behind `/assets/games/<shortname>/<kind>/<hash>`, the fetching
 * half of `lib/games/art.ts` in the Next app. The URL half is
 * {@see GameArt}.
 *
 * Game art has no moderation queue, because only the owner or a moderator can
 * upload it. The row is read as `anon`, so a hidden game's art is refused like
 * its page. The bytes are hashed, because an upload writes the object before
 * the row, and serving new bytes under the old hash would cache them for a year.
 */
final class StagedGameArt
{
    /**
     * `['bytes' => string, 'mime' => string]`, `['promoted' => url]` when the
     * row names this hash and has no staged copy, or null.
     *
     * Throws {@see SupabaseException} on any other failure.
     *
     * @return array{bytes: string, mime: string}|array{promoted: string}|null
     */
    public static function fetch(Client $anon, Client $admin, string $shortname, string $kind, string $hash): ?array
    {
        $row = $anon->from('game')
            ->select($kind.'_path,'.$kind.'_staged_tier')
            ->eq('shortname', $shortname)
            ->eq($kind.'_hash', $hash)
            ->maybeSingle();

        $path = $row[$kind.'_path'] ?? null;
        if ($row === null || $path === null || $path === '') {
            return null;
        }

        $staged = $row[$kind.'_staged_tier'] ?? null;
        if ($staged === null) {
            return ['promoted' => Cdn::staticTierUrl($path)];
        }
        if ($staged !== 'bucket') {
            return null;
        }

        $bytes = Staging::download($admin, $path);
        if ($bytes === null || hash('sha256', $bytes) !== $hash) {
            return null;
        }

        return ['bytes' => $bytes, 'mime' => self::mimeForPath($path)];
    }

    /** The upload paths only ever end in one of these two. */
    private static function mimeForPath(string $path): string
    {
        return match (true) {
            str_ends_with($path, '.png') => 'image/png',
            str_ends_with($path, '.webp') => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
