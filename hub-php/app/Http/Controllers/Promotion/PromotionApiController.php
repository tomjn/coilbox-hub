<?php

namespace App\Http\Controllers\Promotion;

use App\Domain\Moderation\Meters;
use App\Domain\Moderation\Orphans;
use App\Domain\Promotion\PromotionJob;
use App\Http\Controllers\Controller;
use App\Supabase\Supabase;
use App\Support\Api;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `/api/v1/promotion`, the daily promotion job's API (#616). Every route sits
 * behind {@see \App\Http\Middleware\PromotionJobToken}. Nothing here is for a
 * browser, so there are no CORS headers, and nothing may be cached.
 */
class PromotionApiController extends Controller
{
    private function job(): PromotionJob
    {
        return new PromotionJob(Supabase::admin());
    }

    private static function ok(mixed $body): JsonResponse
    {
        return new JsonResponse($body, 200, ['Cache-Control' => 'no-store'], Api::JSON_FLAGS);
    }

    /**
     * A list of strings from the JSON body, or null when it is not one.
     *
     * @return list<string>|null
     */
    private static function strings(Request $request, string $key): ?array
    {
        $value = $request->json($key);
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                return null;
            }
        }

        return $value;
    }

    private static function limit(Request $request, int $default): int
    {
        return max(1, min(PromotionJob::MAX_LIMIT, (int) $request->query('limit', (string) $default)));
    }

    public function withdrawals(): JsonResponse
    {
        return self::ok($this->job()->withdrawals());
    }

    public function recordWithdrawn(Request $request): JsonResponse
    {
        $ids = self::strings($request, 'ids');
        if ($ids === null) {
            return Api::error('Send "ids", a list of asset ids.', 400);
        }

        return self::ok(['settled' => $this->job()->recordWithdrawn($ids)]);
    }

    public function due(Request $request): JsonResponse
    {
        return self::ok($this->job()->due(self::limit($request, 200), CarbonImmutable::now('UTC')));
    }

    public function stillDue(Request $request): JsonResponse
    {
        $ids = self::strings($request, 'ids');
        if ($ids === null) {
            return Api::error('Send "ids", a list of asset ids.', 400);
        }

        return self::ok(['ids' => $this->job()->stillDue($ids)]);
    }

    public function pendingDeletions(): JsonResponse
    {
        return self::ok($this->job()->pendingDeletions());
    }

    public function promote(Request $request): JsonResponse
    {
        $ids = self::strings($request, 'ids');
        $paths = self::strings($request, 'paths');
        if ($ids === null || $paths === null || count($ids) !== count($paths)) {
            return Api::error('Send "ids" and "paths", two lists of the same length.', 400);
        }

        return self::ok($this->job()->promote($ids, $paths));
    }

    public function clearPromoted(Request $request): JsonResponse
    {
        $ids = self::strings($request, 'ids');
        if ($ids === null) {
            return Api::error('Send "ids", a list of asset ids.', 400);
        }

        return self::ok(['cleared' => $this->job()->clearPromoted($ids)]);
    }

    public function readStaged(Request $request): Response|JsonResponse
    {
        $path = (string) $request->query('path', '');
        $bytes = $path === '' ? null : $this->job()->readStaged($path);
        if ($bytes === null) {
            return Api::error('Nothing the job may read is staged at that path.', 404);
        }

        return new Response($bytes, 200, ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store']);
    }

    public function reserve(Request $request): JsonResponse
    {
        $paths = self::strings($request, 'paths');
        $queued = $request->json('queued_is_claimed');
        if ($paths === null || ! is_bool($queued)) {
            return Api::error('Send "paths", a list of staged paths, and "queued_is_claimed", true or false.', 400);
        }

        return self::ok(['paths' => $this->job()->reserve($paths, $queued)]);
    }

    public function discard(Request $request): JsonResponse
    {
        $paths = self::strings($request, 'paths');
        if ($paths === null) {
            return Api::error('Send "paths", a list of staged paths.', 400);
        }
        $deleted = $this->job()->discard($paths);
        if ($deleted === null) {
            return Api::error('Reserve every path before deleting it. Nothing was deleted.', 409);
        }

        return self::ok(['deleted' => $deleted]);
    }

    public function release(Request $request): JsonResponse
    {
        $paths = self::strings($request, 'paths');
        if ($paths === null) {
            return Api::error('Send "paths", a list of staged paths.', 400);
        }

        return self::ok(['released' => $this->job()->release($paths)]);
    }

    public function unclaimed(Request $request): JsonResponse
    {
        return self::ok(Orphans::unclaimed(Supabase::admin(), self::limit($request, Orphans::CLEANUP_BATCH)));
    }

    public function abandoned(): JsonResponse
    {
        return self::ok(Orphans::abandonedDeletions(Supabase::admin(), CarbonImmutable::now('UTC')));
    }

    public function games(): JsonResponse
    {
        return self::ok($this->job()->games());
    }

    public function clearGameStagedTier(Request $request): JsonResponse
    {
        $fields = [];
        foreach (['shortname', 'kind', 'path', 'hash'] as $key) {
            $value = $request->json($key);
            if (! is_string($value) || $value === '') {
                return Api::error('Send "shortname", "kind", "path" and "hash".', 400);
            }
            $fields[$key] = $value;
        }
        if (! in_array($fields['kind'], PromotionJob::GAME_IMAGE_KINDS, true)) {
            return Api::error('"kind" is logo, banner or card.', 400);
        }

        return self::ok(['cleared' => $this->job()->clearGameStagedTier($fields['shortname'], $fields['kind'], $fields['path'], $fields['hash'])]);
    }

    public function meters(): JsonResponse
    {
        return self::ok(Meters::fetch(Supabase::admin(), CarbonImmutable::now('UTC')));
    }
}
