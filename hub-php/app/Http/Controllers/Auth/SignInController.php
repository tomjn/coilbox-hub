<?php

namespace App\Http\Controllers\Auth;

use App\Auth\CoilboxSignIn;
use App\Auth\GoTrue;
use App\Auth\HubAccounts;
use App\Auth\HubSession;
use App\Auth\HubSignIn;
use App\Supabase\Local\SignInStore;
use App\Supabase\SupabaseException;
use App\Support\Site;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Laravel\Socialite\Two\InvalidStateException;
use RuntimeException;

/** `app/auth/*` and `app/sign-in` in the Next app. */
class SignInController
{
    /** The sign in page, which offers each provider. */
    public function page(Request $request): View|RedirectResponse
    {
        $next = self::next($request->query('next'), null);
        if (HubSession::signedIn()) {
            return redirect($next);
        }

        return view('auth.sign-in', ['next' => $next]);
    }

    /**
     * A form post from a sign in button. With no provider it goes to the page
     * that offers them. With one it starts that provider's sign in.
     */
    public function start(Request $request): RedirectResponse|Response
    {
        $next = self::next($request->input('next'), $request->header('referer'));
        $field = $request->input('provider');
        if ($field === null) {
            return redirect('/sign-in?next='.rawurlencode($next), 303);
        }
        $provider = GoTrue::provider((string) $field);
        if ($provider === null) {
            return response('Unknown sign in provider', 400);
        }

        $state = SignInStore::fresh();
        if ($state === SignInStore::FROZEN) {
            return response('Accounts are being moved. Try signing in again in a minute.', 503);
        }
        if ($state === SignInStore::MARIADB) {
            if (! HubSignIn::configured($provider)) {
                return response('Signing in with '.GoTrue::PROVIDERS[$provider].' is not set up yet.', 503);
            }

            return HubSignIn::redirect($provider, $next, null);
        }

        $pkce = GoTrue::pkcePair();
        session()->put('pkce', ['verifier' => $pkce['verifier'], 'next' => $next, 'provider' => $provider, 'link' => false]);
        $redirectTo = Site::absolute('/auth/callback').'?'.http_build_query(['provider' => $provider]);

        return redirect()->away(GoTrue::authorizeUrl($provider, $redirectTo, $pkce['challenge']), 303);
    }

    /** Where the provider sends the browser back, with a code to exchange. */
    public function callback(Request $request): RedirectResponse
    {
        $pending = session()->pull('pkce');
        $code = $request->query('code');
        $provider = GoTrue::provider($request->query('provider'));
        $link = is_array($pending) && ($pending['link'] ?? false);

        if ($link && $provider !== null) {
            $failed = fn (?string $reason) => redirect('/account?link_failed='.$provider.($reason ? '&link_reason='.rawurlencode($reason) : '').'#sign-in-methods');
            if (! is_string($code) || ! is_array($pending)) {
                return $failed($request->query('error_code'));
            }
            try {
                HubSession::start(GoTrue::exchangeCode($code, (string) $pending['verifier']));
            } catch (SupabaseException $e) {
                return $failed($e->errorCode);
            }

            return redirect('/account#sign-in-methods');
        }

        if (! is_string($code) || ! is_array($pending)) {
            return redirect('/?error=no_code');
        }
        try {
            HubSession::start(GoTrue::exchangeCode($code, (string) $pending['verifier']));
        } catch (SupabaseException) {
            return redirect($provider ? '/?error=sign_in_failed&provider='.$provider : '/?error=sign_in_failed');
        }

        return redirect((string) $pending['next']);
    }

    /**
     * Where a provider sends the browser back once the hub runs sign in,
     * `/auth/<provider>/callback`. Socialite checks the `state` it kept in the
     * session, so a callback this browser did not start is refused.
     */
    public function hubCallback(Request $request, string $provider): RedirectResponse
    {
        // A Coilbox sign in, which the browser session knows nothing about.
        if (CoilboxSignIn::owns($request->query('state'))) {
            return CoilboxSignIn::finish($request, $provider);
        }
        $pending = HubSignIn::pending($provider);
        $linkTo = $pending['link'] ?? null;
        $failed = $linkTo !== null
            ? fn (?string $reason) => redirect('/account?link_failed='.$provider.($reason ? '&link_reason='.rawurlencode($reason) : '').'#sign-in-methods')
            : fn (?string $reason) => redirect('/?error=sign_in_failed&provider='.$provider);
        if ($pending === null) {
            return redirect('/?error=no_code');
        }
        if (SignInStore::fresh() !== SignInStore::MARIADB || ! is_string($request->query('code'))) {
            return $failed(is_string($request->query('error')) ? $request->query('error') : null);
        }
        try {
            $profile = HubAccounts::profile($provider, HubSignIn::driver($provider)->user());
        } catch (InvalidStateException) {
            return $failed('bad_oauth_state');
        } catch (GuzzleException|\JsonException|\ErrorException $e) {
            report($e);

            return $failed(null);
        }
        if ($profile['id'] === '') {
            return $failed(null);
        }

        if ($linkTo !== null) {
            // The account that started the link must still be the one signed in.
            if (HubSession::userId() !== $linkTo) {
                return $failed(null);
            }

            return ($reason = HubAccounts::link($linkTo, $profile)) === null
                ? redirect('/account#sign-in-methods')
                : $failed($reason);
        }

        try {
            $userId = HubAccounts::signIn($profile);
        } catch (RuntimeException) {
            return $failed(null);
        }
        HubSession::startHub($userId);

        return redirect($pending['next']);
    }

    public function signOut(): RedirectResponse
    {
        HubSession::signOut();

        return redirect('/', 303);
    }

    /**
     * Where to go after signing in: a same site path from the form, else the
     * page the button was on, else the home page. `signInNext` in the Next app.
     * Only paths, never another origin, so this cannot be an open redirect.
     */
    public static function next(mixed $field, ?string $referer): string
    {
        if (is_string($field) && self::isPath($field)) {
            return $field;
        }
        if ($referer !== null) {
            $parts = parse_url($referer);
            $origin = parse_url(Site::url());
            if (is_array($parts) && is_array($origin)
                && ($parts['scheme'] ?? null) === ($origin['scheme'] ?? null)
                && ($parts['host'] ?? null) === ($origin['host'] ?? null)
                && ($parts['port'] ?? null) === ($origin['port'] ?? null)) {
                $path = $parts['path'] ?? '/';
                $next = isset($parts['query']) ? $path.'?'.$parts['query'] : $path;

                // A same site address can still have a path such as
                // `//evil.example`, which a browser reads as another host.
                return self::isPath($next) ? $next : '/';
            }
        }

        return '/';
    }

    private static function isPath(string $value): bool
    {
        return str_starts_with($value, '/') && ! str_starts_with($value, '//') && ! str_starts_with($value, '/\\');
    }
}
