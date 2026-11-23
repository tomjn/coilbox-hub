<?php

namespace App\Http\Controllers\Gallery;

use App\Auth\HubSession;
use App\Domain\Points\CommunityPoints;
use App\Domain\Profile\Author;
use App\Domain\Profile\Contributions;
use App\Http\Controllers\Controller;
use App\Supabase\Supabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/** `/profile/<name>`, or `/profile/<id>`, which sends the browser on to the name. */
class ProfileController extends Controller
{
    public function show(string $id): Response|RedirectResponse
    {
        $accountId = Contributions::parseAccountId($id)
            ?? (Supabase::configured() ? Author::idForName(Supabase::anon(), $id) : null);
        $contributions = $accountId !== null ? Contributions::cached($accountId) : null;
        if ($contributions === null) {
            return response()->view('gallery.not-found', ['title' => 'Page not found'], 404);
        }

        // Temporary, because a name can change.
        $href = Author::profileHref($accountId, $contributions['name']);
        if ($href !== '/profile/'.rawurlencode($id)) {
            return redirect($href);
        }

        return response()->view('gallery.profile', [
            'title' => $contributions['name'],
            'accountId' => $accountId,
            'contributions' => $contributions,
            // Read here, not in Contributions::cached, so a new award shows straight away.
            'points' => CommunityPoints::total($accountId),
            'own' => HubSession::userId() === $accountId,
        ]);
    }
}
