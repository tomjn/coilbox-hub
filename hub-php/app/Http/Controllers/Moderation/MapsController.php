<?php

namespace App\Http\Controllers\Moderation;

use App\Auth\HubSession;
use App\Domain\Moderation\Access;
use App\Domain\Moderation\GameDecisions;
use App\Domain\Moderation\MapModeration;
use App\Domain\Moderation\Queue;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use App\Support\HubCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * `/moderation/maps` and `/moderation/maps/<slug>`, with their three writes,
 * from `app/moderation/maps` (issues #193 and #394).
 *
 * Each write asks `is_moderator()` of the caller's own session, then writes
 * with the secret key: there is no map equivalent of `asset_event`, so there
 * is no `auth.uid()` worth recording, and `authenticated` holds no write on
 * `public.map`.
 */
class MapsController extends ModerationController
{
    public function index(Request $request): View|RedirectResponse
    {
        $supabase = Access::moderatorClient();
        if ($supabase === null) {
            return Access::refuse('/moderation/maps');
        }
        $q = $request->query('q');
        $term = is_string($q) ? $q : '';
        try {
            $featured = $supabase->from('map')->select('id,slug,map_name,display_name,featured_at')
                ->not('featured_at', 'is', 'null')->order('featured_at', ascending: false)->get();
        } catch (SupabaseException) {
            $featured = [];
        }

        return view('moderation.maps', [
            'term' => $term,
            'conflicts' => MapModeration::conflicts(Supabase::admin()),
            'matches' => MapModeration::search($supabase, $term),
            'featured' => $featured,
        ]);
    }

    public function show(string $slug): View|RedirectResponse
    {
        $supabase = Access::moderatorClient();
        if ($supabase === null) {
            return Access::refuse('/moderation/maps/'.rawurlencode($slug));
        }
        $map = MapModeration::moderatedMap($supabase, $slug);
        if ($map === null) {
            abort(404);
        }

        return view('moderation.map', ['map' => $map]);
    }

    /** Forget what the hub holds about one map. One map per post, never several. */
    public function clear(Request $request): RedirectResponse
    {
        $id = (string) $request->input('map', '');
        if (Access::moderatorClient() !== null && Queue::isUuid($id)) {
            MapModeration::clearFacts(Supabase::admin(), $id);
            // A map whose facts are gone has no page and leaves the listing.
            HubCache::invalidate([HubCache::MAPS]);
        }

        return $this->back('/moderation/maps');
    }

    /** The whole field is the answer, so deleting a word takes the tag off. */
    public function tags(Request $request): RedirectResponse
    {
        $id = (string) $request->input('map', '');
        $slug = (string) $request->input('slug', '');
        if (Access::moderatorClient() !== null && Queue::isUuid($id)) {
            MapModeration::setCuratedTags(Supabase::admin(), $id, MapModeration::parseCuratedTags((string) $request->input('tags', '')));
            HubCache::invalidate([HubCache::map($slug), HubCache::MAPS]);
        }

        return $this->back('/moderation/maps/'.rawurlencode($slug));
    }

    /** Put a map at the top of the catalog, or take it back down (#394). */
    public function feature(Request $request): RedirectResponse
    {
        $messages = MapModeration::FEATURED_MESSAGES;
        $id = trim((string) $request->input('id', ''));
        $slug = trim((string) $request->input('slug', ''));
        $featured = $request->input('featured') === 'true';
        $answer = fn (string $key, bool $ok = false) => $this->answer($request, ['ok' => $ok, 'message' => $messages[$key]], '/moderation/maps');

        if (! Queue::isUuid($id)) {
            return $answer('notSent');
        }
        $session = HubSession::client();
        $userId = HubSession::userId();
        if ($session === null || $userId === null) {
            return $answer('signedOut');
        }
        if (! Access::isModerator($session)) {
            return $answer('notAllowed');
        }
        try {
            $rows = Supabase::admin()->from('map')->update(GameDecisions::who('featured', $featured, $userId))
                ->eq('id', $id)->select('id')->get();
        } catch (SupabaseException $e) {
            Log::error("setMapFeatured: {$id} was not updated", ['error' => $e->getMessage()]);

            return $answer('notSaved');
        }
        if ($rows === []) {
            return $answer('notFound');
        }
        HubCache::invalidate($slug !== '' ? [HubCache::MAPS, HubCache::map($slug)] : [HubCache::MAPS]);

        return $answer($featured ? 'featured' : 'unfeatured', true);
    }
}
