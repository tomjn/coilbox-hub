<?php

namespace App\Auth;

use App\Supabase\Local\Values;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Authenticator apps once sign in runs on the hub (#602, part 3b), in
 * `auth_factor`. A factor is `unverified` until its first code is entered.
 * Entering a code from a verified one raises the session to `aal2`.
 */
final class Factors
{
    public const ISSUER = 'Coilbox Hub';

    /**
     * Every factor on an account, in the shape Supabase Auth gave them, so the
     * account pages read both the same way.
     *
     * @return list<array{id: string, status: string, factor_type: string, created_at: string}>
     */
    public static function all(string $userId): array
    {
        return DB::table('auth_factor')->where('user_id', $userId)->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'status' => (string) $row->status,
                'factor_type' => 'totp',
                'created_at' => Values::timestamp(Values::stored($row->created_at)),
            ])->all();
    }

    public static function hasVerified(string $userId): bool
    {
        return DB::table('auth_factor')->where('user_id', $userId)->where('status', 'verified')->exists();
    }

    /**
     * Starts setting one up, dropping any setup left unfinished.
     *
     * @return array{factorId: string, secret: string, uri: string}
     */
    public static function start(string $userId, string $account): array
    {
        $secret = Totp::secret();
        $id = (string) Str::uuid();
        $now = Values::now();
        DB::transaction(function () use ($userId, $secret, $id, $now) {
            DB::table('auth_factor')->where('user_id', $userId)->where('status', 'unverified')->delete();
            DB::table('auth_factor')->insert([
                'id' => $id,
                'user_id' => $userId,
                'friendly_name' => 'Authenticator added '.gmdate('Y-m-d\TH:i:s.v\Z'),
                'secret' => Crypt::encryptString($secret),
                'status' => 'unverified',
                'last_step' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return ['factorId' => $id, 'secret' => $secret, 'uri' => Totp::uri($secret, $account, self::ISSUER)];
    }

    /** Checks a code against one unverified factor and marks it verified. */
    public static function confirm(string $userId, string $factorId, string $code): bool
    {
        return self::accept($userId, $factorId, 'unverified', $code, ['status' => 'verified']);
    }

    /** Checks a code against one verified factor. */
    public static function check(string $userId, string $factorId, string $code): bool
    {
        return self::accept($userId, $factorId, 'verified', $code, []);
    }

    public static function remove(string $userId, string $factorId): bool
    {
        return DB::table('auth_factor')->where('user_id', $userId)->where('id', $factorId)->delete() > 0;
    }

    /**
     * Accepts a code once. The write only lands while `last_step` is still
     * older, so two requests with the same code cannot both pass.
     *
     * @param  array<string, string>  $changes
     */
    private static function accept(string $userId, string $factorId, string $status, string $code, array $changes): bool
    {
        $row = DB::table('auth_factor')->where('user_id', $userId)->where('id', $factorId)->where('status', $status)->first();
        if ($row === null) {
            return false;
        }
        $last = $row->last_step === null ? null : (int) $row->last_step;
        $step = Totp::match(Crypt::decryptString((string) $row->secret), $code, time(), $last);
        if ($step === null) {
            return false;
        }

        return DB::table('auth_factor')->where('id', $factorId)->where('status', $status)
            ->where(fn ($query) => $query->whereNull('last_step')->orWhere('last_step', '<', $step))
            ->update($changes + ['last_step' => $step, 'updated_at' => Values::now()]) > 0;
    }
}
