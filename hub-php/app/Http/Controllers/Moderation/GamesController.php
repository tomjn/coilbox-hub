<?php

namespace App\Http\Controllers\Moderation;

use App\Auth\HubSession;
use App\Domain\Games\Offers;
use App\Domain\Moderation\Access;
use App\Domain\Moderation\GameDecisions;
use App\Supabase\Supabase;
use App\Supabase\SupabaseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * `/moderation/games`: ownership requests, offered downloads, hidden games and
 * releases, and featured games, from `app/moderation/games/page.tsx`. The
 * writes are the ones `app/games/actions.ts` makes, see {@see GameDecisions}.
 */
class GamesController extends ModerationController
{
    private const PAGE = '/moderation/games';

    public function index(Request $request): View|RedirectResponse
    {
        $supabase = Access::moderatorClient();
        // This page sends a stranger to /moderation, which decides what they see.
        if ($supabase === null) {
            return redirect('/moderation');
        }
        $visibility = $request->query('visibility');
        $flash = is_string($visibility) ? (GameDecisions::VISIBILITY_FLASH_MESSAGES[$visibility] ?? null) : null;

        $read = function (callable $query): array {
            try {
                return $query();
            } catch (SupabaseException) {
                return [];
            }
        };

        return view('moderation.games', [
            'flash' => $flash,
            // The secret key, because offers grant a browser session nothing.
            'offers' => Offers::fetchDownloadOffers(Supabase::admin()),
            'queue' => $read(fn () => $supabase->from('game_ownership_request')
                ->select('id,note,created_at,requested_by_name,game(shortname)')
                ->eq('state', 'open')->order('created_at')->get()),
            'hiddenGames' => $read(fn () => $supabase->from('game')->select('shortname,hidden_at')->not('hidden_at', 'is', 'null')->get()),
            'hiddenVersions' => $read(fn () => $supabase->from('game_version')->select('version,hidden_at,game(shortname)')
                ->not('hidden_at', 'is', 'null')->order('hidden_at', ascending: false)->get()),
            'featured' => $read(fn () => $supabase->from('game')->select('shortname,featured_at')
                ->not('featured_at', 'is', 'null')->order('shortname')->get()),
        ]);
    }

    public function decide(Request $request): RedirectResponse
    {
        $session = HubSession::client();
        $userId = HubSession::userId();
        if ($session !== null && $userId !== null) {
            try {
                $admin = Supabase::admin();
            } catch (SupabaseException) {
                return $this->back(self::PAGE);
            }
            GameDecisions::decideRequest($session, $admin, $userId, (string) $request->input('request_id', ''), $request->input('approve') === 'true');
        }

        return redirect(self::PAGE);
    }

    public function visibility(Request $request): RedirectResponse
    {
        return $this->hideOrShow($request, fn ($session, $admin, $userId) => GameDecisions::setGameVisibility(
            $session, $admin, $userId, (string) $request->input('shortname', ''), $request->input('hidden') === 'true',
        ), (string) $request->input('shortname', '') !== '');
    }

    public function versionVisibility(Request $request): RedirectResponse
    {
        return $this->hideOrShow($request, fn ($session, $admin, $userId) => GameDecisions::setVersionVisibility(
            $session, $admin, $userId, (string) $request->input('shortname', ''), (string) $request->input('version', ''), $request->input('hidden') === 'true',
        ), (string) $request->input('shortname', '') !== '' && (string) $request->input('version', '') !== '');
    }

    public function feature(Request $request): RedirectResponse
    {
        $messages = GameDecisions::FEATURED_MESSAGES;
        if (trim((string) $request->input('shortname', '')) === '') {
            return $this->answer($request, ['ok' => false, 'message' => $messages['notSent']], self::PAGE);
        }
        $session = HubSession::client();
        $userId = HubSession::userId();
        if ($session === null || $userId === null) {
            return $this->answer($request, ['ok' => false, 'message' => $messages['signedOut']], self::PAGE);
        }
        try {
            $admin = Supabase::admin();
        } catch (SupabaseException) {
            return $this->answer($request, ['ok' => false, 'message' => $messages['notSaved']], self::PAGE);
        }

        return $this->answer($request, GameDecisions::setGameFeatured(
            $session, $admin, $userId, (string) $request->input('shortname'), $request->input('featured') === 'true',
        ), self::PAGE);
    }

    public function offer(Request $request): RedirectResponse
    {
        $offerId = GameDecisions::offerId($request->input('offer'));
        $session = HubSession::client();
        $state = match (true) {
            $offerId === null => ['ok' => false, 'message' => GameDecisions::OFFER_MESSAGES['notSent']],
            $session === null => ['ok' => false, 'message' => GameDecisions::OFFER_MESSAGES['signedOut']],
            default => GameDecisions::decideOffer($session, $offerId, $request->input('accept') === 'true'),
        };

        return $this->answer($request, $state, self::PAGE);
    }

    /**
     * The order of answers `setGameVisibility` gives: the form, then the
     * session, then the secret key. The unhide buttons lose their row on
     * success, so they redirect with the confirmation's key instead.
     *
     * @param  callable(\App\Supabase\Client, \App\Supabase\Client, string): array{ok: bool, message: string, flash?: string}  $write
     */
    private function hideOrShow(Request $request, callable $write, bool $sent): RedirectResponse
    {
        $messages = GameDecisions::VISIBILITY_MESSAGES;
        if (! $sent) {
            return $this->answer($request, ['ok' => false, 'message' => $messages['notSent']], self::PAGE);
        }
        $session = HubSession::client();
        $userId = HubSession::userId();
        if ($session === null || $userId === null) {
            return $this->answer($request, ['ok' => false, 'message' => $messages['signedOut']], self::PAGE);
        }
        try {
            $admin = Supabase::admin();
        } catch (SupabaseException) {
            return $this->answer($request, ['ok' => false, 'message' => $messages['notSaved']], self::PAGE);
        }
        $state = $write($session, $admin, $userId);
        if ($state['ok'] && $request->input('onSuccess') === 'moderation') {
            return redirect(self::PAGE.'?visibility='.$state['flash']);
        }

        return $this->answer($request, $state, self::PAGE);
    }
}
