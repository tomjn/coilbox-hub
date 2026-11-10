<?php

namespace App\Http\Controllers\Games;

use App\Auth\HubSession;
use App\Domain\Games\Cached;
use App\Domain\Games\Editor;
use App\Domain\Games\GameEdits;
use App\Domain\Games\Labels;
use App\Domain\Games\Offers;
use App\Domain\Games\Page;
use App\Domain\Moderation\GameDecisions;
use App\Http\Controllers\Moderation\ModerationController;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A game's edit page for its owner or a moderator, `app/games/[shortname]/edit`,
 * and the form posts behind it, the game page's owner controls and a unit's
 * snippet form.
 *
 * Extends the moderation base only for `answer()`, which flashes each form's
 * result back under its own key in place of `useActionState`.
 */
class GameEditController extends ModerationController
{
    public function edit(Request $request, string $shortname): View|RedirectResponse
    {
        $session = HubSession::client();
        $userId = HubSession::userId();
        // The Next page sent a stranger to /auth/sign-in, which never existed.
        if ($session === null || $userId === null) {
            return redirect('/sign-in?next='.rawurlencode('/games/'.$shortname.'/edit'));
        }

        // The visitor's own client, not the cached read, so an owner or a
        // moderator can still reach a game they have hidden.
        $page = Page::loadGamePage($session, $shortname);
        if ($page === null) {
            abort(404);
        }
        // Neither owner nor moderator gets the same answer as an unknown shortname.
        $editable = Editor::editableGame($session, $userId, $shortname);
        if ($editable === null) {
            abort(404);
        }

        // Offers grant a browser session nothing, so they are read with the
        // secret key, and only after the check above found the game.
        try {
            $offers = Offers::fetchDownloadOffers(Supabase::admin(), $editable['id']);
        } catch (SupabaseException) {
            $offers = [];
        }

        // Every release reported so far, hidden ones included, since managing
        // them is what this page is for.
        try {
            $versions = $session->from('game_version')->select('version,hidden_at,game!inner(shortname)')
                ->eq('game.shortname', $shortname)->order('last_seen_at', ascending: false)->get();
        } catch (SupabaseException) {
            $versions = [];
        }

        // The public cached read for the title, so a hidden game gets a plain one.
        $public = Cached::gamePage($shortname);
        $visibility = $request->query('visibility');

        return view('games.edit', [
            'title' => $public !== null ? 'Edit '.Labels::gameTitle($public) : 'Edit game',
            'shortname' => $shortname,
            'page' => $page,
            'gameTitle' => Labels::gameTitle($page),
            'offers' => $offers,
            'versions' => $versions,
            'flash' => is_string($visibility) ? (GameDecisions::VISIBILITY_FLASH_MESSAGES[$visibility] ?? null) : null,
        ]);
    }

    public function details(Request $request, string $shortname): RedirectResponse
    {
        return $this->answer($request, GameEdits::editGameDetails(
            HubSession::client(), HubSession::userId(), $shortname,
            self::text($request, 'display_name'), self::text($request, 'description'),
            self::list($request, 'label'), self::list($request, 'url'),
        ), self::page($shortname));
    }

    public function factions(Request $request, string $shortname): RedirectResponse
    {
        return $this->answer($request, GameEdits::editConquestFactions(
            HubSession::client(), HubSession::userId(), $shortname, self::text($request, 'factions'),
        ), self::page($shortname));
    }

    public function downloads(Request $request, string $shortname): RedirectResponse
    {
        return $this->answer($request, GameEdits::editDownloadSources(
            HubSession::client(), HubSession::userId(), $shortname, self::text($request, 'downloads'),
        ), self::page($shortname));
    }

    public function image(Request $request, string $shortname): RedirectResponse
    {
        return $this->answer($request, GameEdits::uploadGameImage(
            HubSession::client(), HubSession::userId(), $shortname, self::text($request, 'kind'),
            $request->file('image'), self::text($request, 'note'),
        ), self::page($shortname));
    }

    public function removeImage(Request $request, string $shortname): RedirectResponse
    {
        return $this->answer($request, GameEdits::removeGameImage(
            HubSession::client(), HubSession::userId(), $shortname, self::text($request, 'kind'),
        ), self::page($shortname));
    }

    public function snippet(Request $request, string $shortname): RedirectResponse
    {
        return $this->answer($request, GameEdits::setSnippet(
            HubSession::client(), HubSession::userId(), $shortname, self::text($request, 'unit_name'), self::text($request, 'snippet'),
        ), '/games/'.$shortname);
    }

    /** Silent either way, as in the Next app. A signed out visitor is sent to sign in. */
    public function requestOwnership(Request $request, string $shortname): RedirectResponse
    {
        $session = HubSession::client();
        $userId = HubSession::userId();
        if ($session === null || $userId === null) {
            return redirect('/sign-in?next='.rawurlencode('/games/'.$shortname));
        }
        GameEdits::requestOwnership($session, $userId, $shortname, self::text($request, 'note'));

        return redirect('/games/'.$shortname);
    }

    /**
     * Hide or show the game, from the edit page or the game page's own
     * shortcut. The shortcut's page 404s once the game is hidden, so it sends
     * the visitor on to the edit page with the confirmation's key.
     */
    public function visibility(Request $request, string $shortname): RedirectResponse
    {
        return $this->hideOrShow($request, $shortname, fn ($session, $admin, $userId) => GameDecisions::setGameVisibility(
            $session, $admin, $userId, $shortname, $request->input('hidden') === 'true',
        ));
    }

    public function versionVisibility(Request $request, string $shortname): RedirectResponse
    {
        if (self::text($request, 'version') === '') {
            return $this->answer($request, ['ok' => false, 'message' => GameDecisions::VISIBILITY_MESSAGES['notSent']], self::page($shortname));
        }

        return $this->hideOrShow($request, $shortname, fn ($session, $admin, $userId) => GameDecisions::setVersionVisibility(
            $session, $admin, $userId, $shortname, self::text($request, 'version'), $request->input('hidden') === 'true',
        ));
    }

    /** An offered download, decided from the edit page. The database checks who may. */
    public function offer(Request $request, string $shortname): RedirectResponse
    {
        $offerId = GameDecisions::offerId($request->input('offer'));
        $session = HubSession::client();
        $state = match (true) {
            $offerId === null => ['ok' => false, 'message' => GameDecisions::OFFER_MESSAGES['notSent']],
            $session === null => ['ok' => false, 'message' => GameDecisions::OFFER_MESSAGES['signedOut']],
            default => GameDecisions::decideOffer($session, $offerId, $request->input('accept') === 'true'),
        };

        return $this->answer($request, $state, self::page($shortname));
    }

    /** @param callable(\App\Supabase\Client, \App\Supabase\Client, string): array{ok: bool, message: string, flash?: string} $write */
    private function hideOrShow(Request $request, string $shortname, callable $write): RedirectResponse
    {
        $messages = GameDecisions::VISIBILITY_MESSAGES;
        $session = HubSession::client();
        $userId = HubSession::userId();
        if ($session === null || $userId === null) {
            return $this->answer($request, ['ok' => false, 'message' => $messages['signedOut']], self::page($shortname));
        }
        try {
            $admin = Supabase::admin();
        } catch (SupabaseException) {
            return $this->answer($request, ['ok' => false, 'message' => $messages['notSaved']], self::page($shortname));
        }
        $state = $write($session, $admin, $userId);
        if ($state['ok'] && $request->input('onSuccess') === 'edit') {
            return redirect(self::page($shortname).'?visibility='.$state['flash']);
        }

        return $this->answer($request, $state, self::page($shortname));
    }

    private static function page(string $shortname): string
    {
        return '/games/'.$shortname.'/edit';
    }

    private static function text(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }

    /** @return list<mixed> */
    private static function list(Request $request, string $key): array
    {
        $value = $request->input($key);

        return is_array($value) ? array_values($value) : [];
    }
}
