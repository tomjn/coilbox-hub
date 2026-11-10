<?php

namespace App\Domain\Assets;

use App\Auth\GoTrue;
use App\Supabase\Supabase;

/**
 * The body of `GET /api/v1/auth`, `lib/api/auth.ts` with
 * `lib/assets/vocabularyDigest.ts` and `lib/maps/catalogDigest.ts` in the Next
 * app.
 *
 * Tells the desktop app which Supabase project to sign in against, and carries
 * digests of the two vendored files so a shipped build can tell it is behind.
 * Advisory only: the upload route still checks the bytes.
 */
final class AuthDiscovery
{
    public const FORMAT = 'coilbox-hub-auth';

    public const VERSION = 1;

    /** Must match `[auth.email] enable_signup` in supabase/config.toml. */
    public const EMAIL_PASSWORD_ENABLED = false;

    /**
     * The sha256 of the vendored file's bytes, the definition both sides agree
     * on with no canonical form needed. The Next app stored it in `source.json`.
     * The file here is a byte copy, so it is computed.
     */
    public static function digest(string $vendorFile): string
    {
        return 'sha256:'.hash_file('sha256', resource_path('vendor/'.$vendorFile));
    }

    /** Null when the deployment has not configured Supabase, which the route answers with a 503. @return array<string, mixed>|null */
    public static function body(): ?array
    {
        if (! Supabase::configured()) {
            return null;
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'supabase_url' => Supabase::url(),
            'publishable_key' => (string) config('hub.supabase.publishable_key'),
            'asset_vocabulary' => self::digest('asset-vocabulary.json'),
            'map_catalog' => self::digest('map-catalog.json'),
            'providers' => array_keys(GoTrue::PROVIDERS),
            'email_password' => self::EMAIL_PASSWORD_ENABLED,
        ];
    }
}
