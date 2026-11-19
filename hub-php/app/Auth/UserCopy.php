<?php

namespace App\Auth;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Copies every Supabase Auth account and its linked providers into
 * `auth_user` and `auth_identity` (#602, part 3a), for `php artisan
 * users:copy`.
 *
 * Sign in still runs on Supabase, so the copy changes nothing a visitor sees.
 * It can run at any time and as often as needed: it updates accounts in place
 * and removes accounts Supabase no longer has. Sign ins keep the copy current
 * between runs.
 */
final class UserCopy
{
    /** @param Closure(string): void $say */
    public function __construct(private readonly Closure $say) {}

    /**
     * What a copy would do. Writes nothing.
     *
     * @return array{supabase_users: int, supabase_identities: int, mariadb_users: int, mariadb_identities: int, to_add: int, to_remove: int}
     */
    public function counts(): array
    {
        return $this->plan($this->read());
    }

    /**
     * The copy. Answers the counts it checked.
     *
     * @return array{supabase_users: int, supabase_identities: int, mariadb_users: int, mariadb_identities: int, to_add: int, to_remove: int}
     */
    public function run(): array
    {
        $users = $this->read();
        $plan = $this->plan($users);
        $ids = array_map(fn (array $user) => strtolower((string) $user['id']), $users);

        $changed = DB::transaction(function () use ($users, $ids) {
            $changed = 0;
            foreach ($users as $user) {
                $changed += AuthUsers::write($user) ? 1 : 0;
            }
            // Deleted in Supabase since the last copy. Their providers go with them.
            DB::table('auth_user')->whereNotIn('id', $ids === [] ? [''] : $ids)->delete();

            return $changed;
        });

        $after = $this->plan($users);
        ($this->say)(sprintf('Added or updated %d accounts, removed %d.', $changed, $plan['to_remove']));
        if ($after['mariadb_users'] !== $after['supabase_users'] || $after['mariadb_identities'] !== $after['supabase_identities']) {
            throw new RuntimeException(sprintf(
                'The copy does not match Supabase: %d accounts and %d providers in Supabase, %d and %d in MariaDB.',
                $after['supabase_users'], $after['supabase_identities'], $after['mariadb_users'], $after['mariadb_identities'],
            ));
        }

        return $after;
    }

    /**
     * Every account, each read again on its own for its providers, which
     * the list leaves out. An account deleted between the two reads is left out.
     *
     * @return list<array<string, mixed>>
     */
    private function read(): array
    {
        $users = [];
        foreach (GoTrue::adminUsers() as $listed) {
            $user = GoTrue::adminUser((string) $listed['id']);
            if ($user === null) {
                continue;
            }
            if (! is_array($user['identities'] ?? null)) {
                $user['identities'] = [];
            }
            $users[] = $user;
        }

        return $users;
    }

    /**
     * @param  list<array<string, mixed>>  $users
     * @return array{supabase_users: int, supabase_identities: int, mariadb_users: int, mariadb_identities: int, to_add: int, to_remove: int}
     */
    private function plan(array $users): array
    {
        $ids = array_map(fn (array $user) => strtolower((string) $user['id']), $users);
        $held = DB::table('auth_user')->pluck('id')->all();

        return [
            'supabase_users' => count($users),
            'supabase_identities' => array_sum(array_map(fn (array $user) => count($user['identities']), $users)),
            'mariadb_users' => count($held),
            'mariadb_identities' => DB::table('auth_identity')->count(),
            'to_add' => count(array_diff($ids, $held)),
            'to_remove' => count(array_diff($held, $ids)),
        ];
    }
}
