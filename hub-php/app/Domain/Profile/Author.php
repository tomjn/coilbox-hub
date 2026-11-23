<?php

namespace App\Domain\Profile;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/** An account's name as other people see it, from the Next app's `lib/author.ts`. */
final class Author
{
    /** The display name on the account's `profile` row, or null when it has none or the read fails. */
    public static function displayName(Client $supabase, string $userId): ?string
    {
        try {
            $row = $supabase->from('profile')->select('display_name')->eq('user_id', $userId)->maybeSingle();
        } catch (SupabaseException) {
            return null;
        }

        return $row === null ? null : $row['display_name'];
    }

    /**
     * The account with this display name, ignoring capitals as the unique
     * index on `lower(display_name)` does. Null when nobody has it or the read fails.
     */
    public static function idForName(Client $supabase, string $name): ?string
    {
        $pattern = addcslashes($name, '\\%_');
        try {
            $rows = $supabase->from('profile')->select('user_id,display_name')->ilike('display_name', $pattern)->limit(2)->get();
        } catch (SupabaseException) {
            return null;
        }

        return count($rows) === 1 ? (string) $rows[0]['user_id'] : null;
    }

    /**
     * The address of an account's profile, by name when the name can stand in
     * a path. A name with a slash, a name of dots, or one shaped like an
     * account id uses the id, because the browser or the route reads those differently.
     */
    public static function profileHref(string $userId, ?string $name): string
    {
        if ($name === null || trim($name, '.') === '' || str_contains($name, '/') || Contributions::parseAccountId($name) !== null) {
            return '/profile/'.$userId;
        }

        return '/profile/'.rawurlencode($name);
    }
}
