<?php

namespace App\Http\Controllers\Maps;

use App\Domain\Maps\Have;
use App\Domain\Maps\Js;
use App\Domain\Maps\Lookup;
use App\Domain\Maps\MapHave;
use App\Domain\Maps\MapLookup;
use App\Domain\Maps\MapPackList;
use App\Domain\Maps\MapSubmit;
use App\Domain\Maps\Packs;
use App\Domain\Maps\Submit;
use App\Http\Controllers\Controller;
use App\Supabase\Client;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use App\Support\Api;
use App\Support\Bearer;
use App\Support\HubCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The desktop app's map routes: `app/api/v1/maps`, `maps/have`, `maps/lookup`
 * and `map-packs` in the Next app.
 *
 * The three POST answers are functions of the request body, so they carry
 * `no-store`: anything holding them by URL would serve one caller's answer to
 * another.
 */
class MapApiController extends Controller
{
    private const SERVICE_ROLE_ERROR = 'This deployment has not configured Supabase. Set SUPABASE_SERVICE_ROLE_KEY.';

    /**
     * Where a map's facts arrive. Fifty maps travel together and each gets its
     * own outcome inside a 200. The hub works out the slug, the digest and the
     * author keys itself. The token is because this writes: `submitted_by`
     * names an account and the rate limit counts per account.
     */
    public function submit(Request $request): JsonResponse
    {
        $auth = Bearer::authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $max = MapSubmit::maxBytes();
        // The declared length first, as a courtesy. What arrived is measured below.
        $declared = $request->header('Content-Length');
        if (is_numeric($declared) && (float) $declared > $max) {
            return Api::error('A request body may be at most '.$max.' bytes.', 413);
        }
        $raw = $request->getContent();
        if (strlen($raw) > $max) {
            return Api::error('A request body may be at most '.$max.' bytes.', 413);
        }
        if (! Js::parse($raw, $body)) {
            return Api::error('The request body must be JSON.', 400);
        }

        $parsed = MapSubmit::parse($body);
        if (! $parsed['ok']) {
            return Api::error($parsed['error'], $parsed['status']);
        }

        $submissions = [];
        foreach ($parsed['entries'] as $entry) {
            if ($entry['ok']) {
                $submissions[] = Submit::build($entry['entry']);
            }
        }

        // Every entry malformed: nothing to write, and no request spent against the rate limit.
        if ($submissions === []) {
            return $this->answer($parsed['entries'], []);
        }

        $admin = $this->admin();
        if ($admin === null) {
            return Api::error(self::SERVICE_ROLE_ERROR, 503);
        }

        $written = Submit::write($admin, $submissions, $auth->userId);
        if (! $written['ok']) {
            return $written['rateLimited']
                ? Api::error('Too many maps submitted in the last hour. Try again later.', 429)
                : Api::error('The map catalog could not be written just now.', 503);
        }

        // A batch can add maps and change facts on others, so the whole catalog goes stale.
        HubCache::invalidate([HubCache::MAPS]);

        return $this->answer($parsed['entries'], $written['outcomes']);
    }

    /** The batch have check: which of these maps the hub still wants. */
    public function have(Request $request): JsonResponse
    {
        $auth = Bearer::authenticate($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        if (! Js::parse($request->getContent(), $body)) {
            return Api::error('The request body must be JSON.', 400);
        }
        $parsed = MapHave::parse($body);
        if (! $parsed['ok']) {
            return Api::error($parsed['error'], $parsed['status']);
        }

        $admin = $this->admin();
        if ($admin === null) {
            return Api::error(self::SERVICE_ROLE_ERROR, 503);
        }
        $held = Have::fetch($admin, array_column($parsed['keys'], 'mapName'));
        if ($held === null) {
            return Api::error('The map catalog could not be read just now.', 503);
        }

        return Api::json(MapHave::body($parsed['keys'], $held), 200, 'no-store');
    }

    /**
     * What the hub knows about each map. No token: everything here is on the
     * public map pages. The secret key is for reading `asset_licence`.
     */
    public function lookup(Request $request): JsonResponse
    {
        if (! Js::parse($request->getContent(), $body)) {
            return Api::error('The request body must be JSON.', 400);
        }
        $parsed = MapLookup::parse($body);
        if (! $parsed['ok']) {
            return Api::error($parsed['error'], $parsed['status']);
        }

        $admin = $this->admin();
        if ($admin === null) {
            return Api::error(self::SERVICE_ROLE_ERROR, 503);
        }
        // Asked once per distinct name, answered for every position.
        $facts = Lookup::facts($admin, array_values(array_unique($parsed['names'])));
        if ($facts === null) {
            return Api::error('The map catalog could not be read just now.', 503);
        }

        return Api::json(MapLookup::body($parsed['names'], $facts), 200, 'no-store');
    }

    /** Every map pack, for coilbox's Map packs menu. A failed read is a 503, never an empty list. */
    public function packs(): JsonResponse
    {
        $admin = $this->admin();
        $result = $admin === null
            ? ['packs' => [], 'error' => self::SERVICE_ROLE_ERROR]
            : Packs::fetch(Supabase::anon(), $admin);
        if ($result['error'] !== null) {
            Log::error('GET /api/v1/map-packs: the packs could not be read', ['error' => $result['error']]);

            return Api::error('The map packs could not be read just now.', 503);
        }

        return Api::json(MapPackList::body($result['packs']));
    }

    public function preflight()
    {
        return Api::preflight();
    }

    private function admin(): ?Client
    {
        try {
            return Supabase::admin();
        } catch (SupabaseException) {
            return null;
        }
    }

    /**
     * One result per map in request order. A map the function answered nothing
     * for is a hub bug, so the request fails rather than inventing an outcome.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, array{outcome: string, said: string|null}>  $outcomes
     */
    private function answer(array $entries, array $outcomes): JsonResponse
    {
        $results = [];
        foreach ($entries as $entry) {
            if (! $entry['ok']) {
                $results[] = ['map_name' => $entry['mapName'], 'outcome' => 'refused', 'said' => $entry['said']];

                continue;
            }
            $name = $entry['entry']['map_name'];
            $decided = $outcomes[$name] ?? null;
            if ($decided === null) {
                return Api::error('The map catalog could not be written just now.', 503);
            }
            $results[] = $decided['said']
                ? ['map_name' => $name, 'outcome' => $decided['outcome'], 'said' => $decided['said']]
                : ['map_name' => $name, 'outcome' => $decided['outcome']];
        }

        return Api::json(MapSubmit::body($results), 200, 'no-store');
    }
}
