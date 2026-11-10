<?php

namespace App\Http\Controllers\Assets;

use App\Domain\Assets\AssetPath;
use App\Domain\Assets\Bucket;
use App\Domain\Assets\StagedGameArt;
use App\Domain\Games\GameArt;
use App\Http\Controllers\Controller;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Http\Response;

/**
 * Pictures served out of the private staging bucket:
 * `app/assets/staged/[...path]/route.ts` and
 * `app/assets/games/[shortname]/[kind]/[hash]/route.ts` in the Next app.
 *
 * Every refusal is an empty 404 with `no-store`, so a stranger holding a
 * picture's bytes cannot learn whether somebody uploaded them, and a picture
 * approved a minute later shows at once. A served picture is cached for a year,
 * because its URL names its hash.
 */
class StagedPictureController extends Controller
{
    private const CACHE = 'public, max-age=31536000, immutable';

    private const HASH = '/^[0-9a-f]{64}$/D';

    /** An approved picture from the bucket, or a permanent redirect once promoted. */
    public function staged(string $path): Response
    {
        try {
            $picture = Bucket::fetchApprovedStagedPicture(Supabase::anon(), Supabase::admin(), $path);
        } catch (SupabaseException) {
            return self::refused(502);
        }
        if ($picture === null) {
            return self::refused(404);
        }

        // Permanent and cached like the bytes, because both URLs are content addressed.
        // CORS too, or a `crossOrigin = "anonymous"` image load stops at the redirect.
        if (isset($picture['promoted'])) {
            return new Response('', 308, [
                'Location' => $picture['promoted'],
                'Cache-Control' => self::CACHE,
                'Access-Control-Allow-Origin' => '*',
            ]);
        }

        return self::picture($picture['bytes'], AssetPath::isAssetMime($picture['mime']) ? $picture['mime'] : 'application/octet-stream');
    }

    /** A game's logo, banner or card art before promotion, only under the hash it hashes to. */
    public function game(string $shortname, string $kind, string $hash): Response
    {
        if (! in_array($kind, GameArt::KINDS, true) || ! preg_match(self::HASH, $hash)) {
            return self::refused(404);
        }

        try {
            $art = StagedGameArt::fetch(Supabase::anon(), Supabase::admin(), $shortname, $kind, $hash);
        } catch (SupabaseException) {
            return self::refused(502);
        }
        if ($art === null) {
            return self::refused(404);
        }

        // Temporary and never cached: the durable path is overwritten by the next promoted upload.
        if (isset($art['promoted'])) {
            return new Response('', 307, [
                'Location' => $art['promoted'],
                'Cache-Control' => 'no-store',
                'Access-Control-Allow-Origin' => '*',
            ]);
        }

        return self::picture($art['bytes'], $art['mime']);
    }

    /**
     * The hub's own origin serving an uploader's bytes: a type the browser
     * only renders as an image, no sniffing past it, and a policy that lets the
     * bytes reach nothing if opened as a document. CORS, because the 3D map
     * preview reads a height overlay pixel by pixel.
     */
    private static function picture(string $bytes, string $mime): Response
    {
        return new Response($bytes, 200, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => self::CACHE,
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private static function refused(int $status): Response
    {
        return new Response('', $status, ['Cache-Control' => 'no-store']);
    }
}
