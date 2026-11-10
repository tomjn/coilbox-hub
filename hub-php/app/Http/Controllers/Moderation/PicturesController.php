<?php

namespace App\Http\Controllers\Moderation;

use App\Domain\Assets\Asset;
use App\Domain\Assets\AssetPath;
use App\Domain\Moderation\Access;
use App\Domain\Moderation\ApprovedConflicts;
use App\Domain\Moderation\Queue;
use App\Supabase\Supabase;
use App\Support\HubCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Throwable;

/**
 * `/moderation/assets`, the contact sheet, its three writes and the
 * moderator only picture route, from `app/moderation/assets`.
 */
class PicturesController extends ModerationController
{
    /**
     * Private: the moderator's own browser may keep a thumbnail for five
     * minutes, and no shared cache may hold a pending picture at all.
     */
    private const CACHE = 'private, max-age=300';

    public function index(): View|RedirectResponse
    {
        $supabase = Access::moderatorClient();
        if ($supabase === null) {
            return Access::refuse('/moderation/assets');
        }

        return view('moderation.pictures', [
            'queue' => Queue::fetch(Supabase::admin()),
            // The moderator's own session: the function asks is_moderator() itself.
            'disagreements' => ApprovedConflicts::fetch($supabase),
        ]);
    }

    /** Approve everything ticked, in one write, as the moderator. */
    public function approve(Request $request): RedirectResponse
    {
        $supabase = Access::moderatorClient();
        if ($supabase !== null) {
            Queue::approve($supabase, Queue::pictureIds((array) $request->input('asset', [])));
            // Approving is what makes a picture public, wherever it is drawn.
            HubCache::invalidate([HubCache::ASSETS]);
        }

        return $this->back('/moderation/assets');
    }

    /**
     * Reject one picture, saying which kind. An unrecognised kind is dropped
     * rather than guessed at.
     */
    public function reject(Request $request): RedirectResponse
    {
        $supabase = Access::moderatorClient();
        $ids = Queue::pictureIds((array) $request->input('asset', []));
        $kind = (string) $request->input('kind', '');
        if ($supabase !== null && $ids !== [] && in_array($kind, Asset::MODERATOR_REJECTION_KINDS, true)) {
            Queue::reject($supabase, $ids[0], $kind);
            HubCache::invalidate([HubCache::ASSETS]);
        }

        return $this->back('/moderation/assets');
    }

    /** Take a disagreement off the list. The picture is untouched, so no cache moves. */
    public function acknowledge(Request $request): RedirectResponse
    {
        $supabase = Access::moderatorClient();
        $id = ApprovedConflicts::conflictId($request->input('conflict'));
        if ($supabase !== null && $id !== null) {
            ApprovedConflicts::acknowledge($supabase, $id);
        }

        return $this->back('/moderation/assets');
    }

    /**
     * The bytes of one asset, for a moderator and nobody else. The path never
     * leaves the server, and access is decided on every request. Everybody
     * else gets a 404, the same as a missing row, so this is no oracle over
     * the queue.
     */
    public function picture(string $id): Response
    {
        if (Access::moderatorClient() === null) {
            return new Response('', 404);
        }
        try {
            $object = Queue::fetchObject(Supabase::admin(), $id);
        } catch (Throwable) {
            // A broken store gets a clean 502, never the raw storage error.
            return new Response('', 502);
        }
        if ($object === null) {
            return new Response('', 404);
        }

        if ($object['source'] === 'bytes') {
            $body = $object['bytes'];
        } else {
            try {
                $upstream = Http::get($object['url']);
            } catch (Throwable) {
                return new Response('', 502);
            }
            if (! $upstream->successful()) {
                return new Response('', 502);
            }
            $body = $upstream->body();
        }

        return new Response($body, 200, [
            // A pending row's type is whatever an untrusted client declared,
            // and this is the hub's own origin, so only a type the hub stores
            // is passed on. The other two headers close the same hole.
            'Content-Type' => AssetPath::isAssetMime($object['mime']) ? $object['mime'] : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => self::CACHE,
        ]);
    }
}
