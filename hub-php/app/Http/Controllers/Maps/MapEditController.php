<?php

namespace App\Http\Controllers\Maps;

use App\Auth\HubSession;
use App\Domain\Maps\Labels;
use App\Domain\Maps\MapEdits;
use App\Domain\Maps\MapPage;
use App\Http\Controllers\Moderation\ModerationController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A map's edit page and the posts behind it, and the map page's ownership ask.
 *
 * Extends the moderation base only for `answer()`, which flashes each form's
 * result back under its own key, as the game edit page does.
 */
class MapEditController extends ModerationController
{
    public function edit(string $slug): View|RedirectResponse
    {
        $session = HubSession::client();
        if ($session === null || HubSession::userId() === null) {
            return redirect('/sign-in?next='.rawurlencode('/map/'.$slug.'/edit'));
        }
        // A map this visitor may not edit gets the same answer as an unknown slug.
        $map = MapEdits::editableMap($session, $slug);
        $page = $map === null ? null : MapPage::cached($slug);
        abort_if($page === null, 404);

        $mapTitle = Labels::title($page['facts']['display_name'] ?? null, $page['mapName']);

        return view('maps.edit', [
            'title' => 'Edit '.$mapTitle,
            'slug' => $slug,
            'mapTitle' => $mapTitle,
            'downloads' => MapEdits::downloads($session, (string) $map['id']),
        ]);
    }

    public function downloads(Request $request, string $slug): RedirectResponse
    {
        return $this->answer($request, MapEdits::editDownloadSources(
            HubSession::client(), HubSession::userId(), $slug, self::text($request, 'downloads'),
        ), '/map/'.$slug.'/edit');
    }

    /** The map page says what happened. A signed out visitor is sent to sign in. */
    public function requestOwnership(Request $request, string $slug): RedirectResponse
    {
        $session = HubSession::client();
        $userId = HubSession::userId();
        if ($session === null || $userId === null) {
            return redirect('/sign-in?next='.rawurlencode('/map/'.$slug));
        }
        $answer = MapEdits::requestOwnership($session, $userId, $slug, self::text($request, 'target'), self::text($request, 'note'));

        return redirect('/map/'.$slug)->with('mapOwnership', $answer);
    }

    private static function text(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }
}
