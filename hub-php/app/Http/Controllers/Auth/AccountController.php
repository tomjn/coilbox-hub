<?php

namespace App\Http\Controllers\Auth;

use App\Auth\AuthUsers;
use App\Auth\Factors;
use App\Auth\GoTrue;
use App\Auth\HubAccounts;
use App\Auth\HubSession;
use App\Auth\HubSignIn;
use App\Auth\QrCode;
use App\Domain\Account\AccountRules;
use App\Supabase\Client;
use App\Supabase\Local\ItemRules;
use App\Supabase\Local\ItemStore;
use App\Supabase\Local\PictureStore;
use App\Supabase\Local\Values;
use App\Supabase\SupabaseException;
use App\Support\HubCache;
use App\Support\Nav;
use App\Support\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * `/account` and `/account/verify`, with the actions from app/account/*.ts.
 *
 * Each action is a plain form post that redirects back with its result
 * flashed under the form's own key, in place of `useActionState`.
 */
class AccountController
{
    private const CODE_REFUSED = 'That code did not match. Check the clock on the device with your authenticator and try the newest code.';

    private const CODE_UNREADABLE = 'Type the code from your authenticator app, digits only.';

    private const NEEDS_CODE_FIRST = 'Enter a code from the authenticator you already have before changing your authenticators.';

    public const CAPABILITIES = ['can_seed_unit_assets', 'can_publish_unreviewed', 'can_moderate', 'can_replace_any_asset'];

    public function show(Request $request): View|RedirectResponse
    {
        $client = HubSession::client();
        $userId = HubSession::userId();
        if ($client === null || $userId === null) {
            return redirect('/publish');
        }
        $published = (int) $client->from('item')->select('id', count: 'exact')->eq('author_id', $userId)->limit(1)->getWithCount()['count'];
        $name = $client->from('profile')->select('display_name')->eq('user_id', $userId)->maybeSingle()['display_name'] ?? null;
        if ($name === null) {
            abort(500, 'Could not read your display name. Reload the page to try again.');
        }
        $user = self::account() ?? abort(500, 'Could not read your account. Reload the page to try again.');
        $identities = $user['identities'] ?? [];
        if ($identities === []) {
            abort(500, 'Could not read how you sign in. Reload the page to try again.');
        }
        $authenticators = self::verifiedFactors($user);
        $access = array_map(fn ($capability) => self::capabilityAccess($client, $capability), self::CAPABILITIES);
        $verified = HubSession::aal() === 'aal2';
        $holdsCapability = array_filter($access, fn ($answer) => $answer !== 'none') !== [];

        $failed = GoTrue::provider($request->query('link_failed'));
        $linkFailure = $failed ? AccountRules::linkFailureMessage($failed, is_string($request->query('link_reason')) ? $request->query('link_reason') : null) : null;

        return view('auth.account', [
            'userId' => $userId,
            'name' => $name,
            'published' => $published,
            'methods' => AccountRules::signInMethods($identities),
            'linkable' => AccountRules::linkableProviders($identities),
            'authenticators' => $authenticators,
            'verified' => $verified,
            'access' => $access,
            'holdsCapability' => $holdsCapability,
            'showAuthenticators' => $holdsCapability || $authenticators !== [],
            'linkFailure' => $linkFailure,
            'enrolment' => session('enrolment'),
        ]);
    }

    public function rename(Request $request): RedirectResponse
    {
        $client = HubSession::client() ?? abort(redirect('/publish'));
        $checked = AccountRules::checkDisplayName($request->input('displayName'));
        $typed = is_string($request->input('displayName')) ? $request->input('displayName') : null;
        if (isset($checked['error'])) {
            return redirect('/account')->with('rename', ['error' => $checked['error'], 'typed' => $typed]);
        }
        try {
            $rows = $client->from('profile')->update(['display_name' => $checked['name']])
                ->eq('user_id', (string) HubSession::userId())->select('display_name')->get();
        } catch (SupabaseException $e) {
            return redirect('/account')->with('rename', ['error' => AccountRules::renameErrorMessage($e->errorCode), 'typed' => $typed]);
        }
        if ($rows === []) {
            return redirect('/account')->with('rename', ['error' => AccountRules::renameErrorMessage(null), 'typed' => $typed]);
        }
        HubCache::invalidate([HubCache::ITEMS]);
        HubSession::refreshNav();

        return redirect('/account')->with('rename', ['saved' => $checked['name']]);
    }

    public function delete(Request $request): RedirectResponse
    {
        $client = HubSession::client() ?? abort(redirect('/'));
        $userId = (string) HubSession::userId();
        $name = $client->from('profile')->select('display_name')->eq('user_id', $userId)->maybeSingle()['display_name'] ?? null;
        if ($name === null) {
            return redirect('/account')->with('delete', ['error' => 'Could not read your display name, so nothing was deleted. Try again.']);
        }
        if (! AccountRules::confirmsDeletion($request->input('confirm'), $name)) {
            return redirect('/account')->with('delete', ['error' => "That is not your name. Type {$name} exactly to delete your account."]);
        }
        // Asked first, so a deletion during the picture copy is refused rather than missed.
        $picturesLocal = PictureStore::writeTarget() === PictureStore::MARIADB;
        $itemsLocal = ItemStore::writeTarget() === ItemStore::MARIADB;
        try {
            GoTrue::adminDeleteUser($userId);
        } catch (SupabaseException $e) {
            // Made on the hub after sign in moved there, so Supabase never had it.
            if (! HubSession::onHub() || $e->status !== 404) {
                throw $e;
            }
        }
        AuthUsers::forget($userId);
        if ($itemsLocal) {
            ItemRules::forgetAccount($userId);
        }
        if ($picturesLocal) {
            // `asset.uploaded_by` references `auth.users` on delete set null,
            // and its update trigger moves `updated_at`. That foreign key
            // cannot reach MariaDB.
            DB::table('asset')->where('uploaded_by', $userId)->update(['uploaded_by' => null, 'updated_at' => Values::now()]);
        }
        HubCache::invalidate([HubCache::ITEMS]);
        HubSession::signOut();

        return redirect('/?deleted=1');
    }

    /** Starts linking another provider, `app/auth/link/route.ts`. */
    public function link(Request $request): RedirectResponse
    {
        HubSession::signedIn() || abort(redirect('/publish'));
        $provider = GoTrue::provider($request->input('provider'));
        if ($provider === null) {
            abort(400, 'Unknown sign in provider');
        }
        $user = self::account();
        if ($user !== null && self::verifiedFactors($user) !== [] && HubSession::aal() !== 'aal2') {
            return redirect(self::stepUpPath('/account#sign-in-methods'));
        }
        if (HubSession::onHub()) {
            if (! HubSignIn::configured($provider)) {
                return redirect('/account?link_failed='.$provider.'#sign-in-methods');
            }

            return HubSignIn::redirect($provider, '/account', HubSession::userId());
        }
        $token = (string) HubSession::accessToken();
        $pkce = GoTrue::pkcePair();
        session()->put('pkce', ['verifier' => $pkce['verifier'], 'next' => '/account', 'provider' => $provider, 'link' => true]);
        $redirectTo = Site::absolute('/auth/callback').'?'.http_build_query(['provider' => $provider, 'link' => 1]);
        try {
            return redirect()->away(GoTrue::linkIdentityUrl($token, $provider, $redirectTo, $pkce['challenge']), 303);
        } catch (SupabaseException $e) {
            return redirect('/account?link_failed='.$provider.'&link_reason='.rawurlencode((string) $e->errorCode).'#sign-in-methods');
        }
    }

    public function unlink(Request $request): RedirectResponse
    {
        HubSession::signedIn() || abort(redirect('/publish'));
        $fail = fn (string $error) => redirect('/account#sign-in-methods')->with('unlink', ['error' => $error, 'identityId' => $request->input('identityId')]);
        $user = self::account();
        if ($user === null) {
            return $fail('Could not read how you sign in. Try again.');
        }
        if (self::verifiedFactors($user) !== [] && HubSession::aal() !== 'aal2') {
            return $fail('Enter a code from your authenticator before changing how you sign in.');
        }
        $identities = $user['identities'] ?? [];
        $owned = collect($identities)->firstWhere('identity_id', $request->input('identityId'));
        if ($owned === null) {
            return $fail('That is not one of the ways you sign in.');
        }
        if (count($identities) <= 1) {
            return $fail(AccountRules::LAST_METHOD_REASON);
        }
        if (HubSession::onHub()) {
            if (! HubAccounts::unlink((string) HubSession::userId(), (string) $owned['identity_id'])) {
                return $fail('Could not remove that way of signing in. Try again.');
            }

            return redirect('/account#sign-in-methods');
        }
        try {
            GoTrue::unlinkIdentity((string) HubSession::accessToken(), (string) $owned['identity_id']);
        } catch (SupabaseException) {
            return $fail('Could not remove that way of signing in. Try again.');
        }
        // So the removed provider no longer leads to this account in the hub's copy.
        $user['identities'] = array_values(array_filter($identities, fn ($identity) => $identity !== $owned));
        AuthUsers::keep($user);

        return redirect('/account#sign-in-methods');
    }

    public function startEnrolment(): RedirectResponse
    {
        HubSession::signedIn() || abort(redirect('/publish'));
        $fail = fn (string $error) => redirect('/account#second-factor')->with('enrol', ['error' => $error]);
        $user = self::account();
        if ($user === null) {
            return $fail('Could not read your authenticators. Try again.');
        }
        if (self::verifiedFactors($user) !== [] && HubSession::aal() !== 'aal2') {
            return $fail(self::NEEDS_CODE_FIRST);
        }
        if (HubSession::onHub()) {
            $userId = (string) HubSession::userId();
            $started = Factors::start($userId, Nav::current()['name'] ?? $userId);
            session()->put('enrolment', ['factorId' => $started['factorId'], 'qrCode' => QrCode::dataUri($started['uri']), 'uri' => $started['uri'], 'secret' => $started['secret']]);

            return redirect('/account#second-factor');
        }
        $token = (string) HubSession::accessToken();
        // A setup somebody abandoned would otherwise block a new one.
        foreach ($user['factors'] ?? [] as $factor) {
            if (($factor['status'] ?? '') === 'unverified') {
                GoTrue::unenrol($token, (string) $factor['id']);
            }
        }
        try {
            $factor = GoTrue::enrolTotp($token, 'Authenticator added '.gmdate('Y-m-d\TH:i:s.v\Z'));
        } catch (SupabaseException) {
            return $fail('Could not start setting up an authenticator. Try again.');
        }
        session()->put('enrolment', [
            'factorId' => (string) $factor['id'],
            'qrCode' => (string) ($factor['totp']['qr_code'] ?? ''),
            'secret' => (string) ($factor['totp']['secret'] ?? ''),
        ]);

        return redirect('/account#second-factor');
    }

    public function confirmEnrolment(Request $request): RedirectResponse
    {
        HubSession::signedIn() || abort(redirect('/publish'));
        $fail = fn (string $error) => redirect('/account#second-factor')->with('confirm', ['error' => $error]);
        $code = AccountRules::readCode($request->input('code'));
        if ($code === null) {
            return $fail(self::CODE_UNREADABLE);
        }
        $user = self::account();
        $pending = collect($user['factors'] ?? [])->first(fn ($factor) => ($factor['id'] ?? null) === $request->input('factorId') && ($factor['status'] ?? '') === 'unverified');
        if ($pending === null) {
            session()->forget('enrolment');

            return $fail('That setup has expired or finished already. Start again.');
        }
        if (HubSession::onHub()) {
            if (! Factors::confirm((string) HubSession::userId(), (string) $pending['id'], $code)) {
                return $fail(self::CODE_REFUSED);
            }
            HubSession::upgrade();
            session()->forget('enrolment');

            return redirect('/account#second-factor')->with('confirm', ['done' => true, 'hub' => true]);
        }
        try {
            HubSession::replaceTokens(GoTrue::challengeAndVerify((string) HubSession::accessToken(), (string) $pending['id'], $code));
        } catch (SupabaseException) {
            return $fail(self::CODE_REFUSED);
        }
        session()->forget('enrolment');

        return redirect('/account#second-factor')->with('confirm', ['done' => true]);
    }

    public function removeFactor(Request $request): RedirectResponse
    {
        HubSession::signedIn() || abort(redirect('/publish'));
        $fail = fn (string $error) => redirect('/account#second-factor')->with('remove', ['error' => $error, 'factorId' => $request->input('factorId')]);
        if (HubSession::aal() !== 'aal2') {
            return $fail(self::NEEDS_CODE_FIRST);
        }
        $user = self::account();
        $owned = collect($user['factors'] ?? [])->firstWhere('id', $request->input('factorId'));
        if ($owned === null) {
            return $fail('That authenticator is not on your account.');
        }
        if (HubSession::onHub()) {
            if (! Factors::remove((string) HubSession::userId(), (string) $owned['id'])) {
                return $fail('Could not remove that authenticator. Try again.');
            }
        } else {
            try {
                GoTrue::unenrol((string) HubSession::accessToken(), (string) $owned['id']);
            } catch (SupabaseException) {
                return $fail('Could not remove that authenticator. Try again.');
            }
        }
        HubSession::refreshNav();

        return redirect('/account#second-factor');
    }

    public function verifyPage(Request $request): View|RedirectResponse
    {
        $next = SignInController::next($request->query('next'), null);
        HubSession::signedIn() || abort(redirect('/publish'));
        if (HubSession::aal() === 'aal2') {
            return redirect($next);
        }
        $user = self::account();
        $factors = $user ? self::verifiedFactors($user) : [];
        if ($factors === []) {
            return redirect('/account');
        }

        return view('auth.verify', ['factors' => $factors, 'next' => $next]);
    }

    public function verify(Request $request): RedirectResponse
    {
        HubSession::signedIn() || abort(redirect('/publish'));
        $back = fn (string $error) => redirect()->back()->with('verify', ['error' => $error]);
        $code = AccountRules::readCode($request->input('code'));
        if ($code === null) {
            return $back(self::CODE_UNREADABLE);
        }
        $user = self::account();
        $verified = $user ? self::verifiedFactors($user) : [];
        $named = $request->input('factorId');
        $chosen = $named
            ? collect($verified)->firstWhere('id', $named)
            : (count($verified) === 1 ? $verified[0] : null);
        if ($chosen === null) {
            return $back('Choose one of your authenticators.');
        }
        if (HubSession::onHub()) {
            if (! Factors::check((string) HubSession::userId(), (string) $chosen['id'], $code)) {
                return $back(self::CODE_REFUSED);
            }
            HubSession::upgrade();
        } else {
            try {
                HubSession::replaceTokens(GoTrue::challengeAndVerify((string) HubSession::accessToken(), (string) $chosen['id'], $code));
            } catch (SupabaseException) {
                return $back(self::CODE_REFUSED);
            }
        }

        return redirect(SignInController::next($request->input('next'), null));
    }

    public static function stepUpPath(string $next): string
    {
        return '/account/verify?next='.rawurlencode($next);
    }

    /**
     * The signed in account's providers and authenticators, from whichever
     * runs sign in, in the shape Supabase Auth gave them. Null when signed
     * out or Supabase no longer takes the token.
     *
     * @return array<string, mixed>|null
     */
    private static function account(): ?array
    {
        if (HubSession::onHub()) {
            $userId = (string) HubSession::userId();

            return ['id' => $userId, 'identities' => HubAccounts::identities($userId), 'factors' => Factors::all($userId)];
        }
        $token = HubSession::accessToken();

        return $token === null ? null : GoTrue::user($token);
    }

    /**
     * @param  array<string, mixed>  $user
     * @return list<array{id: string, createdAt: string}>
     */
    private static function verifiedFactors(array $user): array
    {
        $out = [];
        foreach ($user['factors'] ?? [] as $factor) {
            if (($factor['status'] ?? '') === 'verified' && ($factor['factor_type'] ?? 'totp') === 'totp') {
                $out[] = ['id' => (string) $factor['id'], 'createdAt' => (string) ($factor['created_at'] ?? '')];
            }
        }

        return $out;
    }

    private static function capabilityAccess(Client $client, string $capability): string
    {
        try {
            $answer = $client->rpc('capability_access', ['capability' => $capability]);
        } catch (SupabaseException) {
            return 'none';
        }

        return in_array($answer, ['granted', 'verify', 'enrol', 'none'], true) ? $answer : 'none';
    }
}
