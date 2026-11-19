<?php

namespace App\Supabase\Local;

use App\Supabase\SupabaseException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Gives a new account its profile once profiles are in MariaDB (#601).
 *
 * In Supabase the `profile_on_sign_up` trigger on `auth.users` called
 * `public.seed_profile` for every new account. That trigger cannot reach
 * MariaDB, so the hub calls {@see seed()} whenever it sees an account sign in
 * or use an access token. The name rules are `seed_profile`'s.
 */
final class Profiles
{
    /** The sign in provider's fields tried for a name, in order. */
    private const NAME_FIELDS = ['full_name', 'name', 'preferred_username', 'user_name'];

    private const MIN = 2;

    private const MAX = 40;

    /**
     * Makes sure this Supabase Auth user has a profile and answers its display
     * name. Does nothing and answers null while profiles are in Supabase. A
     * database failure is reported, not thrown, so it never stops a sign in.
     *
     * @param  array<string, mixed>  $user  as Supabase Auth gives it: `id`, `email`, `user_metadata`
     */
    public static function seed(array $user): ?string
    {
        try {
            if (ItemStore::fresh() !== ItemStore::MARIADB || ! isset($user['id'])) {
                return null;
            }

            return self::insert($user);
        } catch (QueryException|SupabaseException $e) {
            report($e);

            return null;
        }
    }

    /** @param array<string, mixed> $user */
    private static function insert(array $user): string
    {
        $userId = strtolower((string) $user['id']);
        $metadata = is_array($user['user_metadata'] ?? null) ? $user['user_metadata'] : [];
        $base = self::base($userId, $metadata, is_string($user['email'] ?? null) ? $user['email'] : null);

        for ($number = 1; ; $number++) {
            $held = DB::table('profile')->where('user_id', $userId)->value('display_name');
            if (is_string($held)) {
                return $held;
            }
            $suffix = $number === 1 ? '' : ' '.$number;
            $candidate = $suffix === '' ? $base : rtrim(mb_substr($base, 0, self::MAX - mb_strlen($suffix)), ' ').$suffix;
            $now = Values::now();
            $inserted = DB::table('profile')->insertOrIgnore([
                'user_id' => $userId, 'display_name' => $candidate, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($inserted > 0) {
                return $candidate;
            }
        }
    }

    /**
     * The name to start from: the provider's, then the part of the email
     * before the `@`, then `Coilbox user` and the start of the id.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function base(string $userId, array $metadata, ?string $email): string
    {
        foreach (self::NAME_FIELDS as $field) {
            $value = $metadata[$field] ?? null;
            if (is_string($value) && self::fits($name = Tables::btrim($value))) {
                return $name;
            }
        }
        if ($email !== null && self::fits($local = Tables::btrim(explode('@', $email)[0]))) {
            return $local;
        }

        return 'Coilbox user '.substr(str_replace('-', '', $userId), 0, 8);
    }

    private static function fits(string $name): bool
    {
        $length = mb_strlen($name);

        return $length >= self::MIN && $length <= self::MAX;
    }
}
