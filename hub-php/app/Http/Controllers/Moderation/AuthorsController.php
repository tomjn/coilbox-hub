<?php

namespace App\Http\Controllers\Moderation;

use App\Domain\Moderation\Access;
use App\Domain\Moderation\AuthorMerge;
use App\Supabase\Supabase;
use App\Support\HubCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * `/moderation/authors`, merging the author keys that are one person, from
 * `app/moderation/authors` (issues #193 and #442).
 *
 * Search, tick the keys, press "Merge selected" (a GET back here with the
 * ticked keys), then pick the key to keep and the name. Reads use the
 * moderator's own session, because the views are public. The two writes use
 * the secret key after asking `is_moderator()`.
 */
class AuthorsController extends ModerationController
{
    public const ANSWERS = [
        'merged' => 'Merged. Every listing follows it from the next request.',
        'chained' => "Not merged. The key you kept is itself merged into another author. Keep that author's key instead.",
        'refused' => 'Not merged. Tick at least two keys, or one key and a name to show it under.',
    ];

    public function index(Request $request): View|RedirectResponse
    {
        $supabase = Access::moderatorClient();
        if ($supabase === null) {
            return Access::refuse('/moderation/authors');
        }
        $outcome = self::first($request->query('outcome'));
        $search = trim(self::first($request->query('q')));
        $ticked = AuthorMerge::selectedKeys(self::all($request, 'merge'));
        $aliases = AuthorMerge::aliases($supabase);

        return view('moderation.authors', [
            'answer' => self::ANSWERS[$outcome] ?? null,
            'search' => $search,
            'authors' => AuthorMerge::counts($supabase, $search),
            'aliases' => $aliases,
            'merged' => AuthorMerge::byTarget($aliases),
            'selected' => AuthorMerge::authors($supabase, $ticked),
            'choices' => AuthorMerge::nameChoices(AuthorMerge::spellings($supabase, $ticked)),
            'ticked' => $ticked,
        ]);
    }

    /**
     * Merge the ticked keys into the one kept. The answer rides back in the
     * URL, and a merge that did not happen keeps the ticked keys so the form
     * is still open under it.
     */
    public function merge(Request $request): RedirectResponse
    {
        if (Access::moderatorClient() === null) {
            return $this->back('/moderation/authors');
        }
        $keys = array_map('strval', (array) $request->input('key', []));
        $typedName = trim((string) $request->input('typed_name', ''));
        $outcome = AuthorMerge::merge(
            Supabase::admin(),
            (string) $request->input('into', ''),
            $keys,
            $typedName === '' ? (string) $request->input('name', '') : $typedName,
            // A note is a sentence saying why these are one person.
            mb_substr((string) $request->input('note', ''), 0, 500),
        );
        // A merge changes the credits on every map that person made.
        HubCache::invalidate([HubCache::MAPS]);

        $next = ['outcome' => $outcome];
        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $next['q'] = $search;
        }
        // Encoded as URLSearchParams does, spaces as plus signs.
        $query = http_build_query($next);
        if ($outcome !== 'merged') {
            foreach ($keys as $key) {
                $query .= '&merge='.urlencode($key);
            }
        }

        return redirect('/moderation/authors?'.$query);
    }

    /** Withdraw a merge, so the keys count separately again. */
    public function unmerge(Request $request): RedirectResponse
    {
        $fromKey = (string) $request->input('from', '');
        if (Access::moderatorClient() !== null && $fromKey !== '') {
            AuthorMerge::unmerge(Supabase::admin(), $fromKey);
            HubCache::invalidate([HubCache::MAPS]);
        }

        return $this->back('/moderation/authors');
    }

    /**
     * Every value of a repeated query parameter. PHP keeps only the last of
     * `?merge=a&merge=b`, and the ticked boxes arrive exactly like that.
     *
     * @return list<string>
     */
    private static function all(Request $request, string $name): array
    {
        $out = [];
        foreach (explode('&', (string) $request->server('QUERY_STRING', '')) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($pair !== '' && urldecode($key) === $name) {
                $out[] = urldecode($value);
            }
        }

        return $out;
    }

    private static function first(mixed $value): string
    {
        $value = is_array($value) ? ($value[0] ?? '') : $value;

        return is_string($value) ? $value : '';
    }
}
