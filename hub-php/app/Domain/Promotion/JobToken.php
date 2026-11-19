<?php

namespace App\Domain\Promotion;

/**
 * The promotion job's bearer token (#616).
 *
 * The token is 32 random bytes, so a plain SHA-256 hash is enough to keep it
 * out of the server's config without a slow password hash. The hub keeps only
 * the hash, and compares in constant time. The token only opens
 * `/api/v1/promotion`. It cannot sign anybody in or reach any other route.
 */
final class JobToken
{
    /** Whether the presented token matches the current or the next hash. */
    public static function accepts(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        $presented = hash('sha256', $token);
        $accepted = false;
        foreach ([config('hub.promotion.token_hash'), config('hub.promotion.next_token_hash')] as $hash) {
            // Both are compared, so the time taken does not say which matched.
            if (is_string($hash) && strlen($hash) === 64 && hash_equals($hash, $presented)) {
                $accepted = true;
            }
        }

        return $accepted;
    }

    /** A fresh token and its hash, for `php artisan promotion:token`. @return array{token: string, hash: string} */
    public static function make(): array
    {
        $token = 'cbpj_'.bin2hex(random_bytes(32));

        return ['token' => $token, 'hash' => hash('sha256', $token)];
    }
}
