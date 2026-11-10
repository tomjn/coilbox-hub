<?php

namespace App\Http\Controllers\Gallery;

use App\Domain\Container\Json;
use App\Domain\Gallery\GalleryQuery;
use App\Http\Controllers\Controller;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The raw container at `/i/<id>` and the whole public gallery at `/export`,
 * ported from `app/i/[id]/route.ts` and `app/export/route.ts`.
 *
 * Containers are read as `container::text` and decoded here into objects, so a
 * `{}` inside one is served as `{}` and not turned into `[]` on the way through.
 */
class ContainerController extends Controller
{
    /** Read per page. `max_rows` can cap a response below it, which {@see GalleryQuery::fetchAllPages()} survives. */
    private const EXPORT_PAGE_SIZE = 1000;

    private const EXPORT_COLUMNS = 'id,kind,mode,title,description,game_name,game_key,map_name,tags,container:container::text,author_name,created_at,updated_at';

    /**
     * What an Import button points Coilbox at. These URLs get pasted into
     * Discord and outlive the site's look, so this stays apart from the item
     * page. A withdrawn item is invisible to the read policy, so it is "not
     * found" without this route knowing about moderation.
     */
    public function show(string $id): Response|JsonResponse
    {
        try {
            $row = Supabase::anon()->from('item')->select('container:container::text')->eq('id', $id)->maybeSingle();
        } catch (SupabaseException) {
            $row = null;
        }
        if ($row === null) {
            return new JsonResponse(['error' => 'No such item.'], 404, Api::CORS_HEADERS);
        }

        // Short, because withdrawing something has to take it away. Long
        // enough to absorb a link shared into a busy channel.
        return new Response(Json::encode(Json::decode((string) $row['container'])), 200, Api::CORS_HEADERS + [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=60, s-maxage=60',
        ]);
    }

    /**
     * The exit: the backup, and what lets the community rehost everything if
     * the hub is ever abandoned. Withdrawn items are invisible to the read
     * policy, so there is no filtering here to get wrong.
     */
    public function export(): Response|JsonResponse
    {
        $supabase = Supabase::anon();
        $result = GalleryQuery::fetchAllPages(
            fn (int $from, int $to) => $supabase->from('item')
                ->select(self::EXPORT_COLUMNS, 'exact')
                ->order('created_at', ascending: false)
                ->range($from, $to)
                ->getWithCount(),
            self::EXPORT_PAGE_SIZE,
        );
        if ($result['error'] !== null) {
            return new JsonResponse(['error' => $result['error']], 503, Api::CORS_HEADERS);
        }

        $items = array_map(function (array $row) {
            $row['container'] = Json::decode((string) $row['container']);

            return (object) $row;
        }, $result['data']);

        $body = Json::encode((object) [
            'format' => 'coilbox-hub-export',
            'version' => 1,
            'count' => count($items),
            'items' => $items,
        ]);

        return new Response($body, 200, Api::CORS_HEADERS + [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=300, s-maxage=300',
            'Content-Disposition' => 'inline; filename="coilbox-hub.json"',
        ]);
    }
}
