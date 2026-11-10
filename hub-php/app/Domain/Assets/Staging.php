<?php

namespace App\Domain\Assets;

use App\Supabase\Client;
use App\Supabase\SupabaseException;

/**
 * The staging tier, the private `staged-pictures` Supabase Storage bucket,
 * `lib/assets/staging.ts` in the Next app. Only the admin client can read or
 * write it. Every path here is the content addressed one {@see AssetPath}
 * builds, except a game's logo and banner.
 */
final class Staging
{
    public const BUCKET = 'staged-pictures';

    /**
     * Write one object, refusing to overwrite. Throws
     * {@see StagedAssetExistsException} when an object is already there, which
     * for a content addressed path means nothing needs uploading.
     */
    public static function put(Client $admin, string $path, string $bytes, string $contentType): void
    {
        $key = ltrim($path, '/');
        try {
            $admin->uploadObject(self::BUCKET, $key, $bytes, $contentType, false);
        } catch (SupabaseException $e) {
            if ($e->status === 409) {
                throw new StagedAssetExistsException($key);
            }
            throw $e;
        }
    }

    /** Write a game's logo or banner, replacing what was at that fixed path. */
    public static function putGameImage(Client $admin, string $path, string $bytes, string $contentType): void
    {
        $admin->uploadObject(self::BUCKET, ltrim($path, '/'), $bytes, $contentType, true);
    }

    /** One object's bytes, or null when nothing is at the path. */
    public static function download(Client $admin, string $path): ?string
    {
        return $admin->downloadObject(self::BUCKET, ltrim($path, '/'));
    }

    /** @param list<string> $paths */
    public static function remove(Client $admin, array $paths): void
    {
        $admin->removeObjects(self::BUCKET, array_map(fn (string $p) => ltrim($p, '/'), $paths));
    }
}
