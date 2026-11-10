<?php

namespace App\Http\Controllers\Gallery;

use App\Domain\Container\Json;
use App\Domain\Gallery\ApiItems;
use App\Domain\Gallery\ApiPublish;
use App\Domain\Gallery\GalleryQuery;
use App\Domain\Gallery\Publisher;
use App\Domain\Gallery\SearchParams;
use App\Http\Controllers\Controller;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use App\Support\Api;
use App\Support\Bearer;
use App\Support\HubCache;
use App\Support\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `/api/v1/items`, the desktop app's gallery: list and search, one item,
 * publish, and the import ping. Ported from `app/api/v1/items` in the Next app.
 */
class ItemsApiController extends Controller
{
    /**
     * The same filters, order and page size `/gallery` uses. An unknown
     * parameter or kind is a 400, never a filter quietly not applying.
     */
    public function index(Request $request): JsonResponse
    {
        $parsed = ApiItems::parseFilters(SearchParams::fromRequest($request));
        if (! $parsed['ok']) {
            return Api::error($parsed['error'], 400);
        }
        $filters = $parsed['filters'];

        $supabase = Supabase::anon();
        $page = GalleryQuery::fetchPage(
            fn () => GalleryQuery::applyFilters(
                GalleryQuery::applyOrder($supabase->from('item')->select(GalleryQuery::ITEM_SUMMARY_COLUMNS, 'exact'), $filters->sort)
                    ->range(($filters->page - 1) * GalleryQuery::PAGE_SIZE, $filters->page * GalleryQuery::PAGE_SIZE - 1),
                $filters,
            )->getWithCount(),
            fn () => GalleryQuery::applyFilters(
                $supabase->from('item')->select(GalleryQuery::ITEM_SUMMARY_COLUMNS, 'exact')->range(0, 0),
                $filters,
            )->getWithCount(),
        );
        if ($page['error'] !== null) {
            return Api::error('The gallery could not be read just now.', 503);
        }

        return Api::json(ApiItems::listBody($page['data'], $filters->page, $page['count']));
    }

    /**
     * One item, with `container_url` pointing at `/i/<id>`. A withdrawn item
     * is invisible to the read policy, so it is "not found" here too.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $item = Supabase::anon()->from('item')->select(GalleryQuery::ITEM_SUMMARY_COLUMNS)->eq('id', $id)->maybeSingle();
        } catch (SupabaseException) {
            $item = null;
        }
        if ($item === null) {
            return Api::error('No such item.', 404);
        }

        return Api::json(ApiItems::itemBody($item, Site::url().'/i/'.$item['id']));
    }

    /**
     * Publish with a bearer token, through the same {@see Publisher} the form
     * uses. Nothing in the body about who is publishing is trusted.
     */
    public function store(Request $request): JsonResponse
    {
        $auth = Bearer::authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $body = Json::decode($request->getContent());
        if ($body === null && trim($request->getContent()) !== 'null') {
            return Api::error('The request body must be JSON.', 400);
        }

        $parsed = ApiPublish::parseBody($body);
        if (! $parsed['ok']) {
            return Api::error($parsed['error'], 400);
        }

        $outcome = Publisher::publish($auth->client, $auth->userId, $parsed['fields']);
        if (! $outcome['ok']) {
            return Api::error($outcome['reason'], ApiPublish::statusForFailure($outcome['status']));
        }

        // The same listings the publish form marks stale.
        HubCache::invalidate([HubCache::ITEMS]);

        return Api::json(ApiItems::itemBody($outcome['item'], Site::url().'/i/'.$outcome['item']['id']), 201);
    }

    /**
     * Coilbox pings this once an import from a hub link has been applied
     * (coilbox#1361). Fire and forget, so success is a bare 204. No sign in,
     * the same as `/i/<id>`.
     */
    public function imported(string $id): JsonResponse|Response
    {
        try {
            $counted = Supabase::anon()->rpc('record_item_imported', ['target_id' => $id]);
        } catch (SupabaseException) {
            $counted = false;
        }
        // Nothing is counted for an id that never existed or a withdrawn one.
        if (! $counted) {
            return Api::error('No such item.', 404);
        }

        // The count is printed on the item's page, and only there.
        HubCache::invalidate([HubCache::item($id)]);

        return new Response('', 204, Api::CORS_HEADERS);
    }
}
