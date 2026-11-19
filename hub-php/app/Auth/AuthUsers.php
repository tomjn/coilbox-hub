<?php

namespace App\Auth;

use App\Supabase\Local\LocalException;
use App\Supabase\Local\SignInStore;
use App\Supabase\Local\Values;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The hub's copy of Supabase Auth accounts and their linked providers, in
 * `auth_user` and `auth_identity` (#602, part 3a). Ids, providers and provider
 * user ids are Supabase's, so a later sign in run by the hub can find the
 * account a person already has.
 *
 * {@see keep()} updates one account from the user Supabase Auth hands over at
 * sign in. {@see UserCopy} copies them all.
 */
final class AuthUsers
{
    /**
     * Updates this account's copy, and its providers when the user carries
     * them. A database failure is reported, not thrown, so it never stops a
     * sign in.
     *
     * @param  array<string, mixed>  $user  as Supabase Auth gives it
     */
    public static function keep(array $user): void
    {
        if (! is_string($user['id'] ?? null) || $user['id'] === '') {
            return;
        }
        try {
            if (SignInStore::fresh() === SignInStore::MARIADB) {
                self::addMissing($user);

                return;
            }
            self::write($user);
        } catch (QueryException|LocalException $e) {
            report($e);
        }
    }

    /**
     * Once the hub runs the browser sign in, its tables are the record and
     * Supabase only signs Coilbox in (until #602 part 3c). A Supabase account
     * the hub has not seen is added, with the providers nobody else holds.
     * Nothing the hub holds is changed or moved to another account, except
     * that a Supabase ban carries over.
     *
     * @param  array<string, mixed>  $user
     */
    private static function addMissing(array $user): void
    {
        $record = self::record($user);
        $identities = is_array($user['identities'] ?? null) ? self::identities($record['id'], $user['identities']) : [];
        DB::transaction(function () use ($record, $identities) {
            DB::table('auth_user')->insertOrIgnore([$record + ['copied_at' => Values::now(), 'copy_hash' => '']]);
            // A ban made in Supabase still applies. A longer one made on the hub is kept.
            if ($record['banned_until'] !== null) {
                DB::table('auth_user')->where('id', $record['id'])
                    ->where(fn ($query) => $query->whereNull('banned_until')->orWhere('banned_until', '<', $record['banned_until']))
                    ->update(['banned_until' => $record['banned_until']]);
            }
            foreach ($identities as $identity) {
                $taken = DB::table('auth_identity')->where('id', $identity['id'])
                    ->orWhere(fn ($query) => $query->where('provider', $identity['provider'])->where('provider_id', $identity['provider_id']))
                    ->exists();
                if (! $taken) {
                    DB::table('auth_identity')->insert($identity);
                }
            }
        });
    }

    /**
     * Writes one account. Throws on a database failure.
     *
     * @param  array<string, mixed>  $user
     * @return bool whether anything changed
     */
    public static function write(array $user): bool
    {
        $record = self::record($user);
        $identities = is_array($user['identities'] ?? null) ? self::identities($record['id'], $user['identities']) : null;
        $hash = sha1(json_encode([$record, $identities], JSON_THROW_ON_ERROR));
        if (DB::table('auth_user')->where('id', $record['id'])->value('copy_hash') === $hash) {
            return false;
        }

        DB::transaction(function () use ($record, $identities, $hash) {
            DB::table('auth_user')->upsert(
                [$record + ['copied_at' => Values::now(), 'copy_hash' => $hash]],
                ['id'],
            );
            if ($identities === null) {
                return;
            }
            $ids = array_column($identities, 'id');
            DB::table('auth_identity')->where('user_id', $record['id'])->whereNotIn('id', $ids)->delete();
            foreach ($identities as $identity) {
                // A provider account unlinked from one user and linked to another keeps its provider id.
                DB::table('auth_identity')
                    ->where('provider', $identity['provider'])->where('provider_id', $identity['provider_id'])
                    ->where('id', '!=', $identity['id'])->delete();
            }
            if ($identities !== []) {
                DB::table('auth_identity')->upsert($identities, ['id']);
            }
        });

        return true;
    }

    /** Removes an account and its providers, after it is deleted in Supabase. */
    public static function forget(string $userId): void
    {
        try {
            DB::table('auth_user')->where('id', strtolower($userId))->delete();
        } catch (QueryException $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $user
     * @return array<string, mixed>
     */
    private static function record(array $user): array
    {
        return [
            'id' => strtolower((string) $user['id']),
            'email' => self::text($user['email'] ?? null),
            'email_confirmed_at' => self::time($user['email_confirmed_at'] ?? null),
            'app_metadata' => Values::json(is_array($user['app_metadata'] ?? null) ? $user['app_metadata'] : new \stdClass),
            'user_metadata' => Values::json(is_array($user['user_metadata'] ?? null) ? $user['user_metadata'] : new \stdClass),
            'is_anonymous' => (bool) ($user['is_anonymous'] ?? false),
            'banned_until' => self::time($user['banned_until'] ?? null),
            'deleted_at' => self::time($user['deleted_at'] ?? null),
            'created_at' => self::time($user['created_at'] ?? null) ?? Values::now(),
            'updated_at' => self::time($user['updated_at'] ?? null),
            'last_sign_in_at' => self::time($user['last_sign_in_at'] ?? null),
        ];
    }

    /**
     * @param  array<mixed>  $identities
     * @return list<array<string, mixed>>
     */
    private static function identities(string $userId, array $identities): array
    {
        $rows = [];
        foreach ($identities as $identity) {
            if (! is_array($identity)) {
                continue;
            }
            // `identity_id` is the row's own id. `id` is the provider's id for the person.
            $id = $identity['identity_id'] ?? null;
            $provider = $identity['provider'] ?? null;
            $providerId = $identity['id'] ?? null;
            if (! is_string($id) || ! is_string($provider) || ! is_string($providerId) || $providerId === '') {
                continue;
            }
            $rows[] = [
                'id' => strtolower($id),
                'user_id' => $userId,
                'provider' => $provider,
                'provider_id' => $providerId,
                'identity_data' => Values::json(is_array($identity['identity_data'] ?? null) ? $identity['identity_data'] : new \stdClass),
                'email' => self::text($identity['email'] ?? null),
                'created_at' => self::time($identity['created_at'] ?? null),
                'updated_at' => self::time($identity['updated_at'] ?? null),
                'last_sign_in_at' => self::time($identity['last_sign_in_at'] ?? null),
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['id'], $b['id']));

        return $rows;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function time(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? Values::stored($value) : null;
    }
}
