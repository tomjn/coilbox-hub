<?php

namespace App\Http\Controllers\Gallery;

use App\Auth\HubSession;
use App\Domain\Gallery\Art\ItemArt;
use App\Domain\Gallery\ItemPage;
use App\Domain\Gallery\Labels;
use App\Domain\Gallery\Previews;
use App\Domain\Gallery\Workshop\LobbyCommands;
use App\Domain\Gallery\Workshop\PresetCommands;
use App\Domain\Games\Cached as GamesCached;
use App\Http\Controllers\Controller;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use App\Support\HubCache;
use App\Support\Nav;
use App\Support\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `/item/<id>` and `/item/<id>/withdrawn`, from `app/item/[id]` in the Next
 * app, plus the two forms on the page: reporting and featuring.
 */
class ItemController extends Controller
{
    /** The public page, from the cached public reading. */
    public function show(string $id): Response
    {
        $view = ItemPage::public($id);
        if ($view === null) {
            return $this->notFound($id);
        }
        $item = $view['item'];
        $nav = Nav::current();

        return $this->page($item, $view['pictures'], [
            'owner' => $nav !== null && $item['author_id'] !== null && $nav['id'] === $item['author_id'],
            'moderator' => ($nav['moderation'] ?? null) === 'granted',
            'withdrawn' => false,
        ]);
    }

    /**
     * A withdrawn item, for its author and moderators, read with their own
     * client so row level security decides who sees it. A live item goes back
     * to its public page.
     */
    public function withdrawn(string $id): Response|RedirectResponse
    {
        $supabase = HubSession::client();
        if ($supabase === null) {
            return $this->notFound($id);
        }
        $item = ItemPage::forVisitor($supabase, $id);
        if ($item === null) {
            return $this->notFound($id);
        }
        if (($item['deleted_at'] ?? null) === null) {
            return redirect('/item/'.$id);
        }
        $pictures = ItemPage::pictures($supabase, $this->admin(), $item);

        return $this->page($item, $pictures, [
            'owner' => $item['author_id'] === HubSession::userId(),
            'moderator' => (Nav::current()['moderation'] ?? null) === 'granted',
            'withdrawn' => true,
        ], ['title' => 'Withdrawn', 'robots' => 'noindex']);
    }

    /** Anyone may report, signed in or not. The moderators see the reason. */
    public function report(Request $request, string $id): RedirectResponse
    {
        $reason = trim((string) $request->input('reason', ''));
        if ($reason !== '') {
            $supabase = HubSession::client() ?? Supabase::anon();
            try {
                $supabase->from('report')->insert([
                    'item_id' => (string) $request->input('item_id', $id),
                    'reason' => mb_substr($reason, 0, 1000),
                    'reporter_id' => HubSession::userId(),
                ])->execute();
            } catch (SupabaseException $e) {
                report($e);
            }
            HubCache::invalidate([HubCache::item($id)]);
        }

        return redirect()->back(fallback: '/item/'.$id)->with('reported', $id);
    }

    /**
     * A moderator's feature switch (#395). `set_item_featured` checks again,
     * as the signed in user, before it writes.
     */
    public function feature(Request $request, string $id): RedirectResponse
    {
        $back = redirect()->back(fallback: '/item/'.$id);
        $target = trim((string) $request->input('id', ''));
        if ($target === '') {
            return $back->with('featureState', ['ok' => false, 'message' => 'The form did not reach the hub. Reload the page and try again.']);
        }
        $featured = $request->input('featured') === 'true';
        $supabase = HubSession::client();
        if ($supabase === null) {
            return $back->with('featureState', ['ok' => false, 'message' => 'You are signed out. Sign in, then try again.']);
        }
        try {
            $changed = $supabase->rpc('set_item_featured', ['item_id' => $target, 'featured' => $featured]);
        } catch (SupabaseException $e) {
            if ($e->errorCode === '42501') {
                return $back->with('featureState', ['ok' => false, 'message' => 'Only a moderator can feature a gallery item.']);
            }
            report($e);

            return $back->with('featureState', ['ok' => false, 'message' => 'That could not be saved. Try again in a few minutes.']);
        }
        if ($changed !== true) {
            return $back->with('featureState', ['ok' => false, 'message' => 'Nothing changed. The item may have been withdrawn or removed since this page loaded.']);
        }
        HubCache::invalidate([HubCache::item($target), HubCache::ITEMS]);

        return $back->with('featureState', ['ok' => true, 'message' => $featured ? 'Featured in the gallery.' : 'No longer featured.']);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $pictures
     * @param  array{owner: bool, moderator: bool, withdrawn: bool}  $controls
     * @param  array<string, string>  $meta
     */
    private function page(array $item, array $pictures, array $controls, array $meta = []): Response
    {
        $kind = $item['kind'];
        $game = $item['game_key'];
        // What the catalog calls each unit and what each one does, for a
        // blueprint's roster and colours, a pack's layouts and a project's
        // changelog. No game, or no catalog for it, keeps the raw keys.
        $labels = in_array($kind, ['blueprint', 'mod-project'], true) && $game !== null ? GamesCached::unitNameLabels($game) : [];
        $names = [];
        foreach ($labels as $def => $entry) {
            $names[$def] = ['label' => $entry['label'], 'href' => '/games/'.$game.'/units/'.$entry['name']];
        }
        $roles = in_array($kind, ['blueprint', 'setup-pack'], true) && $game !== null ? GamesCached::unitRoles($game) : [];

        $packMaps = [];
        foreach (Previews::setupPackMaps($item['container']) as $name) {
            if (isset($pictures['packMaps'][$name])) {
                $packMaps[] = ['name' => $name, 'picture' => $pictures['packMaps'][$name], 'catalog' => $pictures['catalog'][$name] ?? null];
            }
        }

        $label = Labels::item($kind, $item['mode']);
        $description = $item['description'] !== '' ? $item['description'] : implode(' - ', array_filter([$label, $item['game_name'], $item['map_name']]));

        return response()->view('gallery.item', [
            'title' => $meta['title'] ?? $item['title'],
            'description' => $description,
            'ogTitle' => $item['title'],
            'ogType' => 'article',
            'robots' => $meta['robots'] ?? null,
            'item' => $item,
            'pictures' => $pictures,
            'packMaps' => $packMaps,
            'names' => $names,
            'roles' => $roles,
            'commands' => $this->commands($item),
            'controls' => $controls,
            'art' => ItemArt::for($kind, $item['mode']),
            'origin' => Site::importOrigin(),
        ]);
    }

    /**
     * What somebody can use without Coilbox (#418): a project's tweak slots
     * or a preset's lobby commands. Null when the payload is not one Coilbox
     * wrote.
     *
     * @param  array<string, mixed>  $item
     */
    private function commands(array $item): ?array
    {
        if ($item['kind'] === 'mod-project') {
            return LobbyCommands::commands($item['payload']);
        }
        if ($item['kind'] === 'preset') {
            // A host matches a faction by its first word, and only the
            // game's full list says whether that reaches one faction or two.
            $factions = $item['game_key'] !== null ? PresetCommands::factionsFor($item['game_key']) : [];

            return PresetCommands::commands($item['payload'], $factions);
        }

        return null;
    }

    private function notFound(string $id): Response
    {
        return response()->view('gallery.item-not-found', ['title' => 'Page not found', 'id' => $id], 404);
    }

    private function admin(): ?\App\Supabase\Client
    {
        try {
            return Supabase::admin();
        } catch (SupabaseException) {
            return null;
        }
    }
}
