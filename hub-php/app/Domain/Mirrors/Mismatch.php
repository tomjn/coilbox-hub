<?php

namespace App\Domain\Mirrors;

use App\Supabase\Supabase;
use App\Supabase\SupabaseException;

/**
 * An anonymous report that a downloaded archive does not hold what the hub
 * says it does. Coilbox sends one when the file's hash matched the catalogue
 * but the archive inside named a different kind, shortname, name or version.
 */
final class Mismatch
{
    public const MAX_BODY_BYTES = 4096;

    private const FIELDS = ['kind', 'shortname', 'internal_name', 'version'];

    /**
     * The hash and the fields Coilbox found, or a message saying what is wrong.
     *
     * @return array{sha256: string, found: array<string, string|null>}|string
     */
    public static function parse(mixed $json): array|string
    {
        if (! is_array($json) || array_is_list($json)) {
            return 'Send the report as a JSON object.';
        }

        $sha256 = $json['sha256'] ?? null;
        if (! is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            return 'sha256 must be 64 lowercase hex characters.';
        }

        $found = $json['found'] ?? null;
        if (! is_array($found) || ($found !== [] && array_is_list($found))) {
            return 'found must be an object.';
        }

        $kept = [];
        foreach ($found as $key => $value) {
            if (! in_array($key, self::FIELDS, true)) {
                return 'found may only hold kind, shortname, internal_name and version.';
            }
            if ($key === 'shortname' && $value === null) {
                $kept[$key] = null;

                continue;
            }
            if (! is_string($value) || mb_strlen($value) > 256) {
                return "found.$key must be text of at most 256 characters.";
            }
            if ($key === 'kind' && $value !== 'map' && $value !== 'game') {
                return 'found.kind must be "map" or "game".';
            }
            $kept[$key] = $value;
        }

        if ($kept === []) {
            return 'found must name at least one field.';
        }

        return ['sha256' => $sha256, 'found' => $kept];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public static function handle(string $raw, ?string $ip): array
    {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return self::fail(400, 'The request body must be JSON.', 'bad_request');
        }
        $report = self::parse($decoded);
        if (is_string($report)) {
            return self::fail(400, $report, 'bad_request');
        }

        try {
            $result = Supabase::admin()->rpc('report_archive_mismatch', [
                'p_sha256' => $report['sha256'],
                'p_found' => (object) $report['found'],
                'p_ip' => $ip,
            ]);
        } catch (SupabaseException) {
            $result = null;
        }

        return match (is_array($result) ? ($result['outcome'] ?? null) : null) {
            'recorded' => ['status' => 202, 'body' => ['outcome' => 'recorded']],
            'already' => ['status' => 200, 'body' => ['outcome' => 'already']],
            'unknown_archive' => self::fail(404, 'The hub lists no archive with this hash.', 'unknown_archive'),
            'agrees' => self::fail(422, 'Every field matches what the hub already says.', 'agrees'),
            'rate_limited' => self::fail(429, 'Too many reports from this address. Try again later.', 'rate_limited'),
            default => self::fail(503, 'Reports are unavailable just now.', 'unavailable'),
        };
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private static function fail(int $status, string $message, string $reason): array
    {
        return ['status' => $status, 'body' => ['error' => $message, 'reason' => $reason]];
    }
}
