<?php

namespace App\Domain\Engines;

use App\Supabase\Local\Values;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** The people a moderator has allowed to upload engines. */
final class EngineUploaders
{
    public static function has(?string $userId): bool
    {
        return $userId !== null && DB::table('engine_uploader')->where('user_id', $userId)->exists();
    }

    /** False when the user already holds the grant. */
    public static function grant(string $userId, string $grantedBy): bool
    {
        try {
            return DB::table('engine_uploader')->insert([
                'user_id' => $userId, 'granted_by' => $grantedBy, 'granted_at' => Values::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /** False when the user held no grant. */
    public static function revoke(string $userId): bool
    {
        return DB::table('engine_uploader')->where('user_id', $userId)->delete() > 0;
    }

    /** @return list<array{user_id: string, granted_by: string, granted_at: string}> */
    public static function all(): array
    {
        return DB::table('engine_uploader')->orderByDesc('granted_at')->orderBy('user_id')->get()
            ->map(fn ($row) => ['user_id' => $row->user_id, 'granted_by' => $row->granted_by, 'granted_at' => $row->granted_at])
            ->all();
    }
}
