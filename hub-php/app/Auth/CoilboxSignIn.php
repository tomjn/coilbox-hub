<?php

namespace App\Auth;

use App\Supabase\Local\Values;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\InvalidStateException;
use RuntimeException;

/**
 * Coilbox's sign in once the hub runs it (#602, part 3c), the steps Supabase
 * Auth ran for `/api/v1/auth/authorize` and the `pkce` grant (#592).
 *
 * 1. Coilbox opens the browser at `/api/v1/auth/authorize` with a provider, a
 *    PKCE challenge and a loopback `redirect_to`.
 * 2. The hub sends the browser to the provider with a `state` starting
 *    `coilbox.`, and the provider comes back to the same
 *    `/auth/<provider>/callback` the browser sign in uses.
 * 3. The hub signs the person in to their account and sends the browser to
 *    `redirect_to` with a one-time `code`.
 * 4. Coilbox swaps the code and its PKCE verifier for tokens.
 *
 * Only Coilbox, holding the verifier, can use the code, and only a browser on
 * the same machine receives it, because `redirect_to` must be the loopback
 * address, as Supabase's allowed list had it.
 */
final class CoilboxSignIn
{
    public const STATE_PREFIX = 'coilbox.';

    private const CODE_PREFIX = 'hubc_';

    /** GoTrue's limits on a PKCE challenge. */
    private const CHALLENGE = '/\A[A-Za-z0-9._~-]{43,128}\z/';

    /** Whether a provider's callback belongs to a Coilbox sign in rather than a browser one. */
    public static function owns(mixed $state): bool
    {
        return is_string($state) && str_starts_with($state, self::STATE_PREFIX);
    }

    /**
     * Checks the query Coilbox sent. Null when it is fine, otherwise the
     * refusal in Supabase Auth's shape.
     *
     * @return array{0: int, 1: array<string, mixed>}|null
     */
    public static function refuse(mixed $provider, mixed $redirectTo, mixed $challenge, mixed $method): ?array
    {
        $provider = GoTrue::provider($provider);
        if ($provider === null || ! HubSignIn::configured($provider)) {
            return HubTokens::refusal(400, 'validation_failed', 'Unsupported provider: provider is not enabled');
        }
        if (! is_string($redirectTo) || ! self::loopback($redirectTo)) {
            return HubTokens::refusal(400, 'validation_failed', 'redirect_to must be http://127.0.0.1 on some port.');
        }
        if (! is_string($challenge) || ! is_string($method) || $challenge === '' || $method === '') {
            return HubTokens::refusal(400, 'validation_failed', 'PKCE flow requires code_challenge_method and code_challenge');
        }
        // Coilbox always sends S256. `plain` would hand the verifier to anybody who sees the address.
        if (strtolower($method) !== 's256') {
            return HubTokens::refusal(400, 'validation_failed', 'code_challenge_method must be s256');
        }
        if (! preg_match(self::CHALLENGE, $challenge)) {
            return HubTokens::refusal(400, 'validation_failed', 'code challenge has to be between 43 and 128 characters, made of letters, digits, hyphens, periods, underscores and tildes');
        }

        return null;
    }

    /** Sends the browser to the provider. Call {@see refuse()} first. */
    public static function start(string $provider, string $redirectTo, string $challenge, string $method): RedirectResponse
    {
        $state = self::STATE_PREFIX.Str::random(40);
        $now = Values::now();
        // Sign ins nobody finished.
        DB::table('auth_sign_in')->where('created_at', '<', self::oldest())->delete();
        DB::table('auth_sign_in')->insert([
            'state_hash' => hash('sha256', $state),
            'code_hash' => null,
            'provider' => $provider,
            'redirect_to' => $redirectTo,
            'code_challenge' => $challenge,
            'code_challenge_method' => 's256',
            'user_id' => null,
            'created_at' => $now,
        ]);
        // Socialite's own state lives in the browser session, which the hub
        // does not share with Coilbox, so the hub checks this one itself.
        $target = HubSignIn::driver($provider)->stateless()->with(['state' => $state])->redirect()->getTargetUrl();

        return redirect()->away($target, 302);
    }

    /**
     * The provider sent the browser back. On to Coilbox's loopback address
     * with a code, or with `error` as Supabase sent it.
     */
    public static function finish(Request $request, string $provider): RedirectResponse
    {
        $row = DB::table('auth_sign_in')
            ->where('state_hash', hash('sha256', (string) $request->query('state')))
            ->whereNull('code_hash')->where('provider', $provider)->where('created_at', '>=', self::oldest())
            ->first();
        if ($row === null) {
            return redirect('/?error=sign_in_failed&provider='.$provider);
        }
        $back = fn (array $params) => redirect()->away(self::withQuery((string) $row->redirect_to, $params), 302);
        $fail = function (string $error, string $description, ?string $code = null) use ($row, $back) {
            DB::table('auth_sign_in')->where('id', $row->id)->delete();

            return $back(array_filter(['error' => $error, 'error_code' => $code, 'error_description' => $description]));
        };

        if (! is_string($request->query('code'))) {
            $error = $request->query('error');

            return $fail(is_string($error) && $error !== '' ? $error : 'server_error', 'The sign in was not completed.');
        }
        try {
            $profile = HubAccounts::profile($provider, HubSignIn::driver($provider)->stateless()->user());
        } catch (InvalidStateException|GuzzleException|\JsonException|\ErrorException $e) {
            report($e);

            return $fail('server_error', 'Could not read who signed in.');
        }
        if ($profile['id'] === '') {
            return $fail('server_error', 'Could not read who signed in.');
        }
        try {
            $userId = HubAccounts::signIn($profile);
        } catch (RuntimeException) {
            return $fail('access_denied', 'User is banned', 'user_banned');
        }

        $code = self::CODE_PREFIX.Str::random(40);
        DB::table('auth_sign_in')->where('id', $row->id)->update(['code_hash' => hash('sha256', $code), 'user_id' => $userId]);

        return $back(['code' => $code]);
    }

    /** Whether a code is one the hub gave, rather than Supabase. */
    public static function ownsCode(string $code): bool
    {
        return str_starts_with($code, self::CODE_PREFIX);
    }

    /**
     * Swaps a code and its verifier for tokens. A code works once, wrong
     * verifier or not.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function exchange(string $code, string $verifier): array
    {
        $row = DB::table('auth_sign_in')->where('code_hash', hash('sha256', $code))->first();
        // Deleting it is what claims it, so two requests cannot both use one code.
        if ($row === null || DB::table('auth_sign_in')->where('id', $row->id)->delete() !== 1) {
            return HubTokens::refusal(404, 'flow_state_not_found', 'invalid flow state, no valid flow state found');
        }
        if (Values::stored($row->created_at) < self::oldest()) {
            return HubTokens::refusal(422, 'flow_state_expired', 'invalid flow state, flow state has expired');
        }
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (! hash_equals((string) $row->code_challenge, $expected)) {
            return HubTokens::refusal(400, 'bad_code_verifier', 'code challenge does not match previously saved code verifier');
        }
        if (! HubAccounts::active((string) $row->user_id)) {
            return HubTokens::refusal(400, 'user_banned', 'User is banned');
        }

        return [200, HubTokens::start((string) $row->user_id)];
    }

    /** `http://127.0.0.1:<port>/<path>`, the one entry for Coilbox on Supabase's allowed list. */
    private static function loopback(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'http'
            && ($parts['host'] ?? null) === '127.0.0.1'
            && isset($parts['port'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && str_starts_with((string) ($parts['path'] ?? ''), '/');
    }

    /** @param array<string, string> $params */
    private static function withQuery(string $url, array $params): string
    {
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($params, '', '&', PHP_QUERY_RFC3986).$fragment;
    }

    private static function oldest(): string
    {
        return CarbonImmutable::now('UTC')->subSeconds((int) config('hub.tokens.sign_in_seconds'))->format('Y-m-d H:i:s.u');
    }
}
