<?php

namespace App\Http\Controllers\Publish;

use App\Auth\GoTrue;
use App\Auth\HubSession;
use App\Domain\Gallery\Labels;
use App\Domain\Gallery\Publisher;
use App\Http\Controllers\Controller;
use App\Supabase\Supabase;
use App\Support\HubCache;
use App\Support\Nav;
use App\Support\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * `/publish`, from `app/publish` in the Next app. The form posts back here and
 * the result is flashed under `publish`, in place of `useActionState`.
 */
class PublishController extends Controller
{
    public function show(): View
    {
        return view('publish.index', [
            'title' => 'Publish',
            'description' => 'Share a '.Labels::kindsSingular().' you have made.',
            // Only decides which panel is drawn. Publishing asks the auth
            // server who is signed in before it writes.
            'signedIn' => HubSession::signedIn(),
            'name' => Nav::current()['name'] ?? null,
        ]);
    }

    public function publish(Request $request): RedirectResponse
    {
        $values = [
            'code' => (string) $request->input('code', ''),
            'title' => (string) $request->input('title', ''),
            'description' => (string) $request->input('description', ''),
            'tags' => (string) $request->input('tags', ''),
        ];
        // What was typed comes back with an error, so a typo in the share link
        // does not cost the description just written.
        $fail = fn (string $error) => redirect('/publish')->with('publish', ['error' => $error, 'values' => $values]);

        $token = HubSession::accessToken();
        $user = $token !== null ? GoTrue::user($token) : null;
        $userId = $user['id'] ?? null;
        if (! is_string($userId) || $userId === '') {
            return $fail('Sign in before publishing.');
        }

        $outcome = Publisher::publish(Supabase::forToken((string) $token), $userId, [
            'code' => $values['code'],
            'title' => $values['title'],
            'description' => $values['description'],
            'tags' => explode(',', $values['tags']),
        ]);
        if (! $outcome['ok']) {
            return $fail($outcome['reason']);
        }

        // The landing page and the gallery both list items and both are
        // cached, so without this a new item would not show for an hour.
        HubCache::invalidate([HubCache::ITEMS]);
        $id = (string) $outcome['item']['id'];

        return redirect('/publish')->with('publish', [
            'publishedId' => $id,
            'shareUrl' => Site::absolute('/i/'.$id),
        ]);
    }
}
