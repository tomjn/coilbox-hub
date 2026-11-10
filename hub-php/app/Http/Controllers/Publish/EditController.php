<?php

namespace App\Http\Controllers\Publish;

use App\Auth\HubSession;
use App\Domain\Gallery\ItemPage;
use App\Http\Controllers\Controller;
use App\Supabase\SupabaseException;
use App\Support\HubCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `/item/<id>/edit` and its two actions, from `app/item/[id]/edit` in the
 * Next app.
 *
 * Editing covers the words around an item, not the item. A changed payload is
 * a republish, so a link people already have keeps meaning what it meant. The
 * database enforces that with a column level grant.
 */
class EditController extends Controller
{
    public function show(string $id): Response|RedirectResponse
    {
        $supabase = HubSession::client();
        $userId = HubSession::userId();
        if ($supabase === null || $userId === null) {
            return redirect('/publish');
        }

        // The read policy lets an author see their own withdrawn items, which
        // is what makes withdrawing reversible.
        try {
            $item = $supabase->from('item')->select('id,title,description,tags,author_id,deleted_at')->eq('id', $id)->maybeSingle();
        } catch (SupabaseException) {
            $item = null;
        }
        // The update policy checks ownership regardless. This only avoids a
        // form that would refuse to save.
        if ($item === null || $item['author_id'] !== $userId) {
            return response()->view('gallery.item-not-found', ['title' => 'Page not found', 'id' => $id], 404);
        }

        // The public cached read, so a withdrawn item gets a plain title.
        $shared = ItemPage::public($id);

        return response()->view('publish.edit', [
            'title' => $shared !== null ? 'Edit '.$shared['item']['title'] : 'Edit item',
            'item' => $item,
            'withdrawn' => ($item['deleted_at'] ?? null) !== null,
        ]);
    }

    public function save(Request $request, string $id): RedirectResponse
    {
        $title = trim((string) $request->input('title', ''));
        if ($title === '') {
            return redirect('/item/'.$id.'/edit')->with('edit', ['error' => 'It needs a title.']);
        }
        $supabase = HubSession::client();
        if ($supabase === null) {
            return redirect('/publish');
        }

        try {
            $supabase->from('item')->update([
                'title' => $title,
                'description' => trim((string) $request->input('description', '')),
                'tags' => self::tags((string) $request->input('tags', '')),
            ])->eq('id', $id)->execute();
        } catch (SupabaseException $e) {
            return redirect('/item/'.$id.'/edit')->with('edit', ['error' => 'Could not save it: '.$e->getMessage()]);
        }

        // The author lands on the item a moment later and has to see what
        // they just wrote.
        HubCache::invalidate([HubCache::item($id), HubCache::ITEMS]);

        return redirect('/item/'.$id);
    }

    /**
     * Withdrawal is the soft delete. The item stops being served and stops
     * appearing, a moderator can still see what was there, and its author can
     * put it back.
     */
    public function withdraw(Request $request, string $id): RedirectResponse
    {
        $withdrawn = $request->input('withdrawn') === 'true';
        $supabase = HubSession::client();
        if ($supabase !== null) {
            try {
                $supabase->from('item')
                    ->update(['deleted_at' => $withdrawn ? now()->utc()->format('Y-m-d\TH:i:s.v\Z') : null])
                    ->eq('id', $id)->execute();
            } catch (SupabaseException $e) {
                report($e);
            }
        }

        HubCache::invalidate([HubCache::item($id), HubCache::ITEMS]);

        // The public page says nothing found for a withdrawn item, to its author too.
        return redirect($withdrawn ? '/item/'.$id.'/withdrawn' : '/item/'.$id);
    }

    /** @return list<string> Lower case, trimmed, no blanks, at most eight. */
    public static function tags(string $typed): array
    {
        $tags = array_values(array_filter(array_map(fn ($tag) => mb_strtolower(trim($tag)), explode(',', $typed)), fn ($tag) => $tag !== ''));

        return array_slice($tags, 0, 8);
    }
}
