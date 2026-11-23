<?php

namespace App\Domain\Mirrors;

use App\Supabase\Client;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Support\Facades\DB;

/**
 * Requests to add a mirror. A signed in user asks from the link the mirror's
 * setup page gives them, and a moderator approves or rejects the request on
 * `/moderation/mirrors`. The hub stores a request only once the mirror has
 * proved it holds its key, and checks again on approval.
 *
 * Callers check who may approve and reject. Approval adds the mirror through
 * `Registry::add`, which checks the moderator again.
 */
final class MirrorRequests
{
    private const MESSAGES = [
        'requested' => 'Your request was sent. A hub moderator will review it, and the mirror starts syncing once they approve it.',
        'approved' => 'Approved. It gets no downloads until its first report passes the hub\'s check.',
        'rejected' => 'Request rejected.',
        'checked' => 'The mirror proved it holds the key the hub has for it.',
        'not_found' => 'That request no longer exists.',
        'bad_id' => 'The ID can only use lowercase letters, digits and hyphens, and must start with a letter or digit. At most 64 characters.',
        'bad_name' => 'The name must be between 1 and 128 characters, with no control characters.',
        'bad_key' => 'The public key must be the 43 character key the mirror\'s setup page shows.',
        'id_taken' => 'Another mirror already has that ID.',
        'key_taken' => 'A mirror with that public key is already on the hub.',
        'unavailable' => 'The hub could not check its mirrors just now. Try again.',
    ];

    /** @return array{ok: bool, message: string} */
    public static function submit(string $userId, string $id, string $name, string $url, string $key, Identity $identity): array
    {
        $invalid = self::invalid($id, $name, $url, $key);
        if ($invalid !== null) {
            return self::answer(false, $invalid);
        }

        try {
            $mirrors = Supabase::admin()->from('mirror')->select('id,public_key')->get();
        } catch (SupabaseException) {
            return self::answer(false, 'unavailable');
        }
        foreach (is_array($mirrors) ? $mirrors : [] as $mirror) {
            if (($mirror['id'] ?? null) === $id) {
                return self::answer(false, 'id_taken');
            }
            if (($mirror['public_key'] ?? null) === $key) {
                return self::answer(false, 'key_taken');
            }
        }

        $failed = $identity->check($url, $id, $key);
        if ($failed !== null) {
            return self::answer(false, $failed);
        }

        $now = self::now();
        DB::table('mirror_request')->upsert(
            ['public_key' => $key, 'mirror_id' => $id, 'name' => $name, 'base_url' => $url, 'requested_by' => $userId, 'requested_at' => $now, 'checked_at' => $now],
            ['public_key'],
        );

        return self::answer(true, 'requested');
    }

    /**
     * Waiting requests, newest first, with the requester's display name when they have one.
     *
     * @return list<array{public_key: string, mirror_id: string, name: string, base_url: string, requested_by: string, requester: ?string, requested_at: string, checked_at: string}>
     */
    public static function pending(): array
    {
        return DB::table('mirror_request')
            ->leftJoin('profile', 'profile.user_id', '=', 'mirror_request.requested_by')
            ->orderByDesc('mirror_request.requested_at')
            ->get(['mirror_request.*', 'profile.display_name as requester'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** @return array{ok: bool, message: string} */
    public static function approve(Client $moderator, string $key, Identity $identity): array
    {
        $request = DB::table('mirror_request')->where('public_key', $key)->first();
        if ($request === null) {
            return self::answer(false, 'not_found');
        }

        $failed = $identity->check($request->base_url, $request->mirror_id, $request->public_key);
        if ($failed !== null) {
            return self::answer(false, $failed);
        }
        DB::table('mirror_request')->where('public_key', $key)->update(['checked_at' => self::now()]);

        $added = Registry::add($moderator, $request->mirror_id, $request->name, $request->base_url, $request->public_key);
        if (! $added['ok']) {
            return $added;
        }
        DB::table('mirror_request')->where('public_key', $key)->delete();

        return self::answer(true, 'approved');
    }

    /** @return array{ok: bool, message: string} */
    public static function reject(string $key): array
    {
        return DB::table('mirror_request')->where('public_key', $key)->delete() > 0
            ? self::answer(true, 'rejected')
            : self::answer(false, 'not_found');
    }

    /**
     * Asks a mirror already on the hub to prove it holds the key on record.
     *
     * @param  array{id: string, base_url: string, public_key: string}  $mirror
     * @return array{ok: bool, message: string}
     */
    public static function checkMirror(array $mirror, Identity $identity): array
    {
        $failed = $identity->check($mirror['base_url'], $mirror['id'], $mirror['public_key']);

        return $failed === null ? self::answer(true, 'checked') : self::answer(false, $failed);
    }

    /** The reason the fields cannot make a mirror, or null when they can. */
    private static function invalid(string $id, string $name, string $url, string $key): ?string
    {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/', $id) !== 1) {
            return 'bad_id';
        }
        $length = mb_strlen($name);
        if (! mb_check_encoding($name, 'UTF-8') || $length < 1 || $length > 128 || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $name) === 1) {
            return 'bad_name';
        }
        if (Identity::parse($url) === null) {
            return 'bad_url';
        }
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $key) !== 1 || strlen((string) B64::decode($key)) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return 'bad_key';
        }

        return null;
    }

    private static function now(): string
    {
        return now()->format('Y-m-d H:i:s.u');
    }

    /** @return array{ok: bool, message: string} */
    private static function answer(bool $ok, string $code): array
    {
        return ['ok' => $ok, 'message' => self::MESSAGES[$code] ?? Identity::MESSAGES[$code]];
    }
}
