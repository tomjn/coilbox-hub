<?php

namespace App\Auth;

use App\Supabase\Local\Profiles;
use App\Supabase\Local\Values;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as ProviderUser;
use RuntimeException;

/**
 * Accounts once sign in runs on the hub (#602, part 3b). A provider account
 * leads to the hub account its `auth_identity` row names, which for anybody
 * from before the switch is their Supabase account's id, so their items, maps
 * and moderator rights stay theirs.
 *
 * A provider account the hub has not seen joins the one account whose
 * confirmed email matches, but only when the provider says the address is
 * verified. Supabase linked identities by verified email the same way.
 * Otherwise it gets a new account.
 */
final class HubAccounts
{
    /**
     * What the hub keeps from a provider's answer. `data` follows the
     * `identity_data` keys Supabase Auth stored, so names and avatars read
     * the same for accounts from before the switch.
     *
     * @return array{provider: string, id: string, email: ?string, verified: bool, data: array<string, mixed>}
     */
    public static function profile(string $provider, ProviderUser $user): array
    {
        $raw = $user->getRaw();
        $id = (string) $user->getId();
        $email = is_string($user->getEmail()) && $user->getEmail() !== '' ? $user->getEmail() : null;
        // GitHub's answer only carries the primary address when it is
        // verified. Twitch only gives out verified addresses. Discord and
        // Google say.
        $verified = $email !== null && match ($provider) {
            'discord' => ($raw['verified'] ?? false) === true,
            'google' => ($raw['email_verified'] ?? false) === true,
            default => true,
        };
        $avatar = $user->getAvatar();
        $data = match ($provider) {
            'github' => [
                'iss' => 'https://api.github.com',
                'name' => $user->getName() ?? $user->getNickname(),
                'full_name' => $user->getName() ?? $user->getNickname(),
                'user_name' => $user->getNickname(),
                'preferred_username' => $user->getNickname(),
            ],
            'discord' => [
                'iss' => 'https://discord.com/api',
                'name' => $user->getNickname(),
                'full_name' => $raw['username'] ?? $user->getName(),
                'custom_claims' => ['global_name' => $raw['global_name'] ?? null],
            ],
            'google' => [
                'iss' => 'https://accounts.google.com',
                'name' => $user->getName(),
                'full_name' => $user->getName(),
            ],
            'twitch' => [
                'iss' => 'https://api.twitch.tv/helix',
                'name' => $raw['login'] ?? $user->getName(),
                'nickname' => $user->getNickname(),
                'full_name' => $user->getName(),
                'user_name' => $raw['login'] ?? null,
                'preferred_username' => $raw['login'] ?? null,
            ],
            default => [],
        };
        $data += [
            'sub' => $id,
            'provider_id' => $id,
            'avatar_url' => $avatar,
            'picture' => $avatar,
            'email' => $email,
            'email_verified' => $verified,
            'phone_verified' => false,
        ];

        return [
            'provider' => $provider,
            'id' => $id,
            'email' => $email,
            'verified' => $verified,
            'data' => array_filter($data, fn ($value) => $value !== null),
        ];
    }

    /**
     * Signs a provider account in and answers the hub account's id.
     *
     * @param  array{provider: string, id: string, email: ?string, verified: bool, data: array<string, mixed>}  $profile
     *
     * @throws RuntimeException when the account is banned
     */
    public static function signIn(array $profile): string
    {
        try {
            return DB::transaction(fn () => self::signInOnce($profile));
        } catch (UniqueConstraintViolationException) {
            // Two first sign ins with one provider account at once. The other won, so find its row.
            return DB::transaction(fn () => self::signInOnce($profile));
        }
    }

    /**
     * Adds a provider account to a signed in account.
     *
     * @param  array{provider: string, id: string, email: ?string, verified: bool, data: array<string, mixed>}  $profile
     * @return string|null null when linked, otherwise Supabase's error code for why not
     */
    public static function link(string $userId, array $profile): ?string
    {
        return DB::transaction(function () use ($userId, $profile) {
            $held = self::identity($profile);
            if ($held !== null && $held->user_id !== $userId) {
                return 'identity_already_exists';
            }
            $now = Values::now();
            self::keepIdentity($userId, $held, $profile, $now);
            self::touch($userId, null, $now, false);

            return null;
        });
    }

    /**
     * An account's providers, in the shape Supabase Auth gave them.
     *
     * @return list<array{identity_id: string, id: string, provider: string, identity_data: array<string, mixed>, email: ?string}>
     */
    public static function identities(string $userId): array
    {
        return DB::table('auth_identity')->where('user_id', $userId)->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($row) => [
                'identity_id' => (string) $row->id,
                'id' => (string) $row->provider_id,
                'provider' => (string) $row->provider,
                'identity_data' => json_decode((string) $row->identity_data, true) ?: [],
                'email' => $row->email,
            ])->all();
    }

    /** Removes one of an account's providers. False when it is not theirs. */
    public static function unlink(string $userId, string $identityId): bool
    {
        return DB::transaction(function () use ($userId, $identityId) {
            $removed = DB::table('auth_identity')->where('user_id', $userId)->where('id', $identityId)->delete() > 0;
            if ($removed) {
                self::touch($userId, null, Values::now(), false);
            }

            return $removed;
        });
    }

    /**
     * The account as {@see Profiles::seed()} reads it.
     *
     * @return array{id: string, email: ?string, user_metadata: array<string, mixed>}|null
     */
    public static function user(string $userId): ?array
    {
        $row = DB::table('auth_user')->where('id', $userId)->first(['id', 'email', 'user_metadata']);

        return $row === null ? null : [
            'id' => (string) $row->id,
            'email' => $row->email,
            'user_metadata' => json_decode((string) $row->user_metadata, true) ?: [],
        ];
    }

    /**
     * The ids of the accounts with this id, or with this email in any case.
     *
     * @return list<string>
     */
    public static function find(string $idOrEmail): array
    {
        $value = mb_strtolower(trim($idOrEmail));
        $byId = DB::table('auth_user')->where('id', $value)->pluck('id')->all();

        return array_values(array_map('strval', $byId !== [] ? $byId : DB::table('auth_user')->whereRaw('lower(email) = ?', [$value])->orderBy('id')->pluck('id')->all()));
    }

    /**
     * When a ban given as `--until` ends, in the stored form. No date means
     * no end, written as the last moment the column holds.
     *
     * @throws \InvalidArgumentException for a date that is not readable or not in the future
     */
    public static function banEnd(mixed $until): string
    {
        if ($until === null || $until === '') {
            return '9999-12-31 23:59:59.000000';
        }
        try {
            $stored = Values::stored((string) $until);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Give --until as a date, such as 2027-01-31.');
        }
        if ($stored <= Values::now()) {
            throw new \InvalidArgumentException('--until must be in the future. Use --lift to end a ban.');
        }

        return $stored;
    }

    /** Sets or lifts a ban. A banned account's sessions end on their next request. */
    public static function ban(string $userId, ?string $until): void
    {
        DB::table('auth_user')->where('id', $userId)->update(['banned_until' => $until, 'updated_at' => Values::now()]);
    }

    /** Whether an account may hold a session: it exists and is not banned. */
    public static function active(string $userId): bool
    {
        $row = DB::table('auth_user')->where('id', $userId)->first(['banned_until', 'deleted_at']);

        return $row !== null && $row->deleted_at === null
            && ($row->banned_until === null || Values::stored($row->banned_until) <= Values::now());
    }

    /** @param array{provider: string, id: string, email: ?string, verified: bool, data: array<string, mixed>} $profile */
    private static function signInOnce(array $profile): string
    {
        $now = Values::now();
        $held = self::identity($profile);
        $userId = $held->user_id ?? ($profile['verified'] ? self::byEmail((string) $profile['email']) : null);
        $created = false;
        if ($userId === null) {
            $userId = self::create($profile, $now);
            $created = true;
        }
        if (! $created && ! self::active($userId)) {
            throw new RuntimeException('That account cannot sign in.');
        }
        self::keepIdentity($userId, $held, $profile, $now);
        self::touch($userId, $profile, $now, true);

        return $userId;
    }

    /** @param array{provider: string, id: string} $profile */
    private static function identity(array $profile): ?object
    {
        return DB::table('auth_identity')->where('provider', $profile['provider'])->where('provider_id', $profile['id'])->first();
    }

    /** The one account with this confirmed email, or null when there is none or more than one. */
    private static function byEmail(string $email): ?string
    {
        $ids = DB::table('auth_user')->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->whereNotNull('email_confirmed_at')->whereNull('deleted_at')->limit(2)->pluck('id')->all();

        return count($ids) === 1 ? (string) $ids[0] : null;
    }

    /** @param array{provider: string, id: string, email: ?string, verified: bool, data: array<string, mixed>} $profile */
    private static function create(array $profile, string $now): string
    {
        $id = strtolower((string) Str::uuid());
        DB::table('auth_user')->insert([
            'id' => $id,
            'email' => $profile['email'],
            'email_confirmed_at' => $profile['verified'] ? $now : null,
            'app_metadata' => Values::json(['provider' => $profile['provider'], 'providers' => [$profile['provider']]]),
            'user_metadata' => Values::json($profile['data']),
            'is_anonymous' => false,
            'created_at' => $now,
            'updated_at' => $now,
            'last_sign_in_at' => $now,
            'copied_at' => $now,
            'copy_hash' => UserCopy::MADE_ON_HUB,
        ]);

        return $id;
    }

    /** @param array{provider: string, id: string, email: ?string, verified: bool, data: array<string, mixed>} $profile */
    private static function keepIdentity(string $userId, ?object $held, array $profile, string $now): void
    {
        if ($held !== null) {
            // Keys Supabase stored and the hub does not make are kept.
            $data = array_replace(json_decode((string) $held->identity_data, true) ?: [], $profile['data']);
            DB::table('auth_identity')->where('id', $held->id)->update([
                'identity_data' => Values::json($data),
                'email' => $profile['email'],
                'updated_at' => $now,
                'last_sign_in_at' => $now,
            ]);

            return;
        }
        DB::table('auth_identity')->insert([
            'id' => strtolower((string) Str::uuid()),
            'user_id' => $userId,
            'provider' => $profile['provider'],
            'provider_id' => $profile['id'],
            'identity_data' => Values::json($profile['data']),
            'email' => $profile['email'],
            'created_at' => $now,
            'updated_at' => $now,
            'last_sign_in_at' => $now,
        ]);
    }

    /**
     * Brings the account's provider list up to date, and on sign in its
     * metadata, which Supabase took from the provider signed in with.
     *
     * @param  array{provider: string, data: array<string, mixed>}|null  $profile
     */
    private static function touch(string $userId, ?array $profile, string $now, bool $signIn): void
    {
        $row = DB::table('auth_user')->where('id', $userId)->first(['app_metadata', 'user_metadata']);
        if ($row === null) {
            return;
        }
        $app = json_decode((string) $row->app_metadata, true) ?: [];
        $app['providers'] = DB::table('auth_identity')->where('user_id', $userId)->orderBy('provider')->distinct()->pluck('provider')->all();
        $changes = ['app_metadata' => Values::json($app), 'updated_at' => $now];
        if ($signIn && $profile !== null) {
            $app['provider'] ??= $profile['provider'];
            $changes['app_metadata'] = Values::json($app);
            $changes['user_metadata'] = Values::json(array_replace(json_decode((string) $row->user_metadata, true) ?: [], $profile['data']));
            $changes['last_sign_in_at'] = $now;
        }
        DB::table('auth_user')->where('id', $userId)->update($changes);
    }
}
