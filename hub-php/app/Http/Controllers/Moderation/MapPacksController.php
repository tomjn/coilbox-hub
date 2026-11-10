<?php

namespace App\Http\Controllers\Moderation;

use App\Auth\HubSession;
use App\Domain\Maps\Packs;
use App\Domain\Moderation\Access;
use App\Domain\Moderation\GameDecisions;
use App\Domain\Moderation\MapPacks;
use App\Domain\Moderation\Queue;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * `/moderation/map-packs` and one pack's page, with their writes, from
 * `app/moderation/map-packs` (tomjn/coilbox#3206).
 *
 * Every write asks `is_moderator()` of the caller's own session and then
 * writes with the secret key, as the map writes do. No cache is named:
 * `GET /api/v1/map-packs` reads the database on every request.
 */
class MapPacksController extends ModerationController
{
    private const LIST = '/moderation/map-packs';

    public function index(): View|RedirectResponse
    {
        $supabase = Access::moderatorClient();
        if ($supabase === null) {
            return Access::refuse(self::LIST);
        }

        return view('moderation.map-packs', Packs::fetch($supabase, Supabase::admin()));
    }

    public function show(string $id): View|RedirectResponse
    {
        $supabase = Access::moderatorClient();
        if ($supabase === null) {
            return Access::refuse(self::LIST.'/'.rawurlencode($id));
        }
        $pack = Queue::isUuid($id) ? MapPacks::one($supabase, Supabase::admin(), $id) : null;
        if ($pack === null) {
            abort(404);
        }

        return view('moderation.map-pack', ['pack' => $pack]);
    }

    /** Start a pack with a title, then go to its page to fill it. */
    public function create(Request $request): RedirectResponse
    {
        $text = MapPacks::readText((string) $request->input('title', ''), (string) $request->input('blurb', ''));
        if ($text === null) {
            return $this->say($request, 'badText');
        }
        if (($refused = $this->refused($request)) !== null) {
            return $refused;
        }
        try {
            $rows = Supabase::admin()->from('map_pack')->insert($text)->select('id')->get();
        } catch (SupabaseException $e) {
            Log::error('createMapPack: the pack was not created', ['error' => $e->getMessage()]);
            $rows = [];
        }
        if (! isset($rows[0]['id'])) {
            return $this->say($request, 'notSaved');
        }

        return redirect(self::LIST.'/'.$rows[0]['id']);
    }

    /** Change a pack's title and blurb. The whole of both fields is the answer. */
    public function save(Request $request): RedirectResponse
    {
        $id = trim((string) $request->input('id', ''));
        if (! Queue::isUuid($id)) {
            return $this->say($request, 'notSent');
        }
        $text = MapPacks::readText((string) $request->input('title', ''), (string) $request->input('blurb', ''));
        if ($text === null) {
            return $this->say($request, 'badText');
        }
        if (($refused = $this->refused($request)) !== null) {
            return $refused;
        }

        return $this->update($request, $id, $text, 'saved');
    }

    /** Put a pack in coilbox's Map packs menu, or take it out. */
    public function feature(Request $request): RedirectResponse
    {
        $id = trim((string) $request->input('id', ''));
        $featured = $request->input('featured') === 'true';
        if (! Queue::isUuid($id)) {
            return $this->say($request, 'notSent');
        }
        if (($refused = $this->refused($request)) !== null) {
            return $refused;
        }

        return $this->update($request, $id, GameDecisions::who('featured', $featured, (string) HubSession::userId()), $featured ? 'featured' : 'unfeatured');
    }

    /** Add a pasted list. The answer names every line that matched nothing. */
    public function add(Request $request): RedirectResponse
    {
        $id = trim((string) $request->input('id', ''));
        if (! Queue::isUuid($id)) {
            return $this->say($request, 'notSent');
        }
        $names = MapPacks::parsePastedMapNames((string) $request->input('names', ''));
        if ($names === []) {
            return $this->say($request, 'nothingPasted');
        }
        if (($refused = $this->refused($request)) !== null) {
            return $refused;
        }

        return $this->answer($request, MapPacks::addMaps(Supabase::admin(), $id, $names), self::LIST.'/'.$id);
    }

    public function remove(Request $request): RedirectResponse
    {
        $id = trim((string) $request->input('id', ''));
        $mapName = (string) $request->input('map', '');
        if (! Queue::isUuid($id) || $mapName === '') {
            return $this->say($request, 'notSent');
        }
        if (($refused = $this->refused($request)) !== null) {
            return $refused;
        }
        try {
            Supabase::admin()->from('map_pack_map')->delete()->eq('pack_id', $id)->eq('map_name', $mapName)->execute();
        } catch (SupabaseException $e) {
            Log::error("removeMapFromPack: {$mapName} was not removed from {$id}", ['error' => $e->getMessage()]);

            return $this->say($request, 'notSaved');
        }

        return $this->say($request, 'removed', true);
    }

    /** Throw a pack away, maps and all, then go back to the list. */
    public function delete(Request $request): RedirectResponse
    {
        $id = trim((string) $request->input('id', ''));
        if (! Queue::isUuid($id)) {
            return $this->say($request, 'notSent');
        }
        if (($refused = $this->refused($request)) !== null) {
            return $refused;
        }
        try {
            Supabase::admin()->from('map_pack')->delete()->eq('id', $id)->execute();
        } catch (SupabaseException $e) {
            Log::error("deleteMapPack: {$id} was not deleted", ['error' => $e->getMessage()]);

            return $this->say($request, 'notSaved');
        }

        return redirect(self::LIST);
    }

    /** @param array<string, mixed> $values */
    private function update(Request $request, string $id, array $values, string $done): RedirectResponse
    {
        try {
            $rows = Supabase::admin()->from('map_pack')->update($values)->eq('id', $id)->select('id')->get();
        } catch (SupabaseException $e) {
            Log::error("map pack {$id} was not updated", ['error' => $e->getMessage()]);

            return $this->say($request, 'notSaved');
        }
        if ($rows === []) {
            return $this->say($request, 'notFound');
        }

        return $this->say($request, $done, true);
    }

    /** Signed out or not a moderator, answered as such. Null when the caller may write. */
    private function refused(Request $request): ?RedirectResponse
    {
        $session = HubSession::client();
        if ($session === null || HubSession::userId() === null) {
            return $this->say($request, 'signedOut');
        }

        return Access::isModerator($session) ? null : $this->say($request, 'notAllowed');
    }

    private function say(Request $request, string $key, bool $ok = false): RedirectResponse
    {
        return $this->answer($request, ['ok' => $ok, 'message' => MapPacks::MESSAGES[$key]], self::LIST);
    }
}
