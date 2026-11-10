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
}
