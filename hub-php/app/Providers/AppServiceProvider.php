<?php

namespace App\Providers;

use App\Auth\HubSession;
use App\Support\Api;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The limits Supabase Auth applies per address, `token_refresh` and
        // `sign_in_sign_ups` under [auth.rate_limit] in supabase/config.toml.
        RateLimiter::for('auth-token', function (Request $request) {
            $refresh = $request->query('grant_type', $request->input('grant_type')) === 'refresh_token';
            $limit = $refresh
                ? Limit::perMinutes(5, 150)->by('refresh|'.$request->ip())
                : Limit::perMinutes(5, 30)->by('sign-in|'.$request->ip());

            return $limit->response(fn (Request $request, array $headers) => Api::error('Too many sign in requests from this address. Try again in a few minutes.', 429)->withHeaders($headers));
        });

        // Starting a Coilbox sign in, which stores it until the person comes
        // back. The same limit as swapping its code.
        RateLimiter::for('auth-authorize', function (Request $request) {
            return Limit::perMinutes(5, 30)->by('authorize|'.$request->ip())
                ->response(fn (Request $request, array $headers) => Api::error('Too many sign in requests from this address. Try again in a few minutes.', 429)->withHeaders($headers));
        });

        // Codes from an authenticator app, per account so a stolen provider
        // account cannot keep guessing. `token_verifications` under
        // [auth.rate_limit] in supabase/config.toml, Supabase's own limit.
        RateLimiter::for('auth-code', function (Request $request) {
            return Limit::perMinutes(5, 30)
                ->by('code|'.(HubSession::userId() ?? $request->ip()))
                ->response(fn (Request $request, array $headers) => response('Too many codes tried. Wait a few minutes, then try the newest code.', 429, $headers));
        });
    }
}
