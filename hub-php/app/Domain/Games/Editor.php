<?php

namespace App\Domain\Games;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * Whether a signed in account may change a game, `lib/games/editor.ts`. The
 * owner may, and so may a moderator, on any game. The client must be the
 * visitor's own, never the secret key, because row level security answers
 * both questions. Null for a game that does not exist, which callers treat
 * the same as one the visitor may not change.
 */
final class Editor
{
    /** @return array{id: string}|null */
    public static function editableGame(Client $supabase, string $userId, string $shortname): ?array
    {
        try {
            // An error asking is_moderator still leaves the owner check below.
            try {
                $moderator = $supabase->rpc('is_moderator') === true;
            } catch (SupabaseException) {
                $moderator = false;
            }
            $query = $supabase->from('game')->select('id')->eq('shortname', $shortname);
            if (! $moderator) {
                $query->eq('owner_user_id', $userId);
            }
            $row = $query->maybeSingle();
        } catch (SupabaseException) {
            return null;
        }

        return $row !== null ? ['id' => (string) $row['id']] : null;
    }
}
