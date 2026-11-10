<?php

namespace App\Http\Controllers\Assets;

use App\Domain\Assets\AssetHaveApi;
use App\Domain\Assets\AssetPicturesApi;
use App\Domain\Assets\AssetResolver;
use App\Domain\Assets\AssetUploadApi;
use App\Domain\Assets\Caps;
use App\Domain\Assets\Have;
use App\Domain\Assets\ImageHeader;
use App\Domain\Assets\Js;
use App\Domain\Assets\SourceConflict;
use App\Domain\Assets\StagedAssetExistsException;
use App\Domain\Assets\Staging;
use App\Domain\Assets\Upload;
use App\Domain\Assets\UploadIp;
use App\Http\Controllers\Controller;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use App\Support\Api;
use App\Support\Bearer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * `/api/v1/assets/pictures`, `/have` and `/upload`, the three routes under
 * `app/api/v1/assets/` in the Next app.
 *
 * None of the three answers may be cached: each goes stale the moment
 * anything is uploaded, approved or promoted.
 */
class AssetApiController extends Controller
{
    private const NO_STORE = 'no-store';

    private const SERVICE_ROLE_ERROR = 'This deployment has not configured Supabase. Set SUPABASE_SERVICE_ROLE_KEY.';

    /**
     * Which of these identities the hub has a picture of, and where. No token:
     * it reads as `anon`, so only approved rows can answer. A failed lookup
     * reads as "no picture" rather than a 503, which is only safe because
     * nothing may cache the answer.
     */
    public function pictures(Request $request): JsonResponse
    {
        if (! Js::parse($request->getContent(), $body)) {
            return Api::error('The request body must be JSON.', 400);
        }
        $parsed = AssetPicturesApi::parse($body);
        if (! $parsed['ok']) {
            return Api::error($parsed['error'], $parsed['status']);
        }

        $held = AssetResolver::fetchHeldAssets(Supabase::anon(), $parsed['keys']);

        return Api::json(AssetPicturesApi::body($parsed['keys'], $held), 200, self::NO_STORE);
    }

    /**
     * The batch have check. Needs a token, because the useful answer covers
     * pending and rejected rows the public cannot see, read with the secret
     * key. The answer never says which state a row is in.
     */
    public function have(Request $request): JsonResponse
    {
        $auth = Bearer::authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        if (! Js::parse($request->getContent(), $body)) {
            return Api::error('The request body must be JSON.', 400);
        }
        $parsed = AssetHaveApi::parse($body);
        if (! $parsed['ok']) {
            return Api::error($parsed['error'], $parsed['status']);
        }

        try {
            $admin = Supabase::admin();
        } catch (SupabaseException) {
            return Api::error(self::SERVICE_ROLE_ERROR, 503);
        }

        $hashes = Have::fetchSourceHashes($admin, array_map(fn (array $key) => $key['identity'], $parsed['keys']));
        if ($hashes === null) {
            return Api::error('The asset index could not be read just now.', 503);
        }

        return Api::json(AssetHaveApi::body($parsed['keys'], $hashes), 200, self::NO_STORE);
    }

    /**
     * The only way a picture reaches the hub. Every refusal runs before the
     * write, so a rejected upload costs nothing:
     *
     * 1. the bearer token verifies
     * 2. the body is multipart with both parts
     * 3. the declaration parses, unknown fields refused
     * 4. the bytes received are the length declared
     * 5. the image header, against the caps for its class
     * 6. the hash of the bytes, which is where they land
     * 7. MIME, size, path, identity and the three quotas
     *
     * 201 for a new row, 200 for a replacement. The answer never says where the
     * bytes went, because the uploader is who a modified client is.
     */
    public function upload(Request $request): JsonResponse
    {
        $auth = Bearer::authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        // `request.formData()` takes multipart and urlencoded bodies and throws on anything else.
        $type = strtolower((string) $request->headers->get('Content-Type', ''));
        if (! str_starts_with($type, 'multipart/form-data') && ! str_starts_with($type, 'application/x-www-form-urlencoded')) {
            return Api::error('The request body must be multipart/form-data with an "asset" JSON part and a "file" part.', 415);
        }

        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return Api::error('The "file" part is required and must carry the encoded bytes.', 400);
        }

        $declared = $request->request->get('asset');
        if (! is_string($declared)) {
            return Api::error('The "asset" part is required and must be the declaration as JSON.', 400);
        }
        if (! Js::parse($declared, $json)) {
            return Api::error('The "asset" part must be JSON.', 400);
        }

        $parsed = AssetUploadApi::parse($json);
        if (! $parsed['ok']) {
            return Api::error($parsed['error'], 400);
        }
        $declaration = $parsed['declaration'];

        // Every quota is measured against the declared size, so it has to be the size that arrived.
        $size = $file->isValid() ? (int) $file->getSize() : 0;
        if ($size !== $declaration['bytes']) {
            return Api::error('The "file" part is '.$size.' bytes and the declaration says '.$declaration['bytes'].'.', 400);
        }

        $bytes = (string) file_get_contents($file->getRealPath());

        $image = Caps::checkImage($declaration['identity']['variant'], $declaration['mime'], substr($bytes, 0, ImageHeader::BYTES));
        if (! $image['ok']) {
            return Api::error($image['error'], $image['status']);
        }

        // The hub's own hash of the bytes. A declared one would let an uploader pick which picture to overwrite.
        $hash = hash('sha256', $bytes);

        try {
            $admin = Supabase::admin();
        } catch (SupabaseException) {
            return Api::error(self::SERVICE_ROLE_ERROR, 503);
        }

        $check = Upload::check($admin, $auth->userId, $declaration, $hash, fn () => Upload::mayReplaceAnyAsset($auth->client));

        // Before the answer either way: the 409 for another account's row is where this case lands.
        if (($check['conflict'] ?? null) !== null) {
            SourceConflict::record($admin, $check['conflict']);
        }
        if (! $check['ok']) {
            return Api::error($check['error'], $check['status']);
        }

        // An object already at a content addressed path holds these exact bytes, so a collision is a reuse.
        try {
            Staging::put($admin, $check['path'], $bytes, $declaration['mime']);
        } catch (StagedAssetExistsException) {
            // Kept as it is.
        } catch (SupabaseException) {
            return Api::error('The asset store would not accept that upload just now.', 502);
        }

        $skipQueue = Upload::skipsQueue($auth->client);

        $assetId = Upload::write($admin, $auth->userId, $declaration, $hash, $check['path'], $image, $check['replacing'], $skipQueue);
        if ($assetId === null) {
            // The object stays. Another row may name it, and the sweep clears it if nothing ever does.
            return Api::error('The asset was uploaded but its record could not be written.', 503);
        }

        // Not on an upload that skipped the queue: the purge trigger only fires on the way into approved.
        if (! $skipQueue) {
            UploadIp::record($admin, $assetId, UploadIp::clientIp($request));
        }

        return Api::json(
            AssetUploadApi::body($skipQueue ? 'approved' : 'pending'),
            $check['replacing'] !== null ? 200 : 201,
            self::NO_STORE,
        );
    }

    public function preflight()
    {
        return Api::preflight();
    }
}
