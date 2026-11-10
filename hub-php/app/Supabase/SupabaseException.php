<?php

namespace App\Supabase;

use RuntimeException;

/**
 * A request to Supabase failed. `code` is PostgREST's or Postgres's error code
 * (for example `PGRST116` or `23505`), the same value supabase-js puts on
 * `error.code`, so ported code can branch on it the same way.
 */
class SupabaseException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $errorCode = null,
        public readonly ?string $details = null,
        public readonly ?string $hint = null,
    ) {
        parent::__construct($message, $status);
    }

    /** @param array<string, mixed>|null $body */
    public static function fromResponse(int $status, ?array $body, string $fallback): self
    {
        return new self(
            (string) ($body['message'] ?? $body['msg'] ?? $body['error_description'] ?? $body['error'] ?? $fallback),
            $status,
            isset($body['code']) ? (string) $body['code'] : (isset($body['error_code']) ? (string) $body['error_code'] : null),
            isset($body['details']) ? (string) $body['details'] : null,
            isset($body['hint']) ? (string) $body['hint'] : null,
        );
    }
}
