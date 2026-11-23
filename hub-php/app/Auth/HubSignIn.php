<?php

namespace App\Auth;

use App\Support\Site;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\TwitchProvider;
use SocialiteProviders\Discord\Provider as DiscordProvider;

/**
 * The browser sign in once it runs on the hub (#602, part 3b): Socialite
 * sends the browser to the provider and reads who came back. Socialite keeps
 * a random `state` in the session and refuses a callback that does not bring
 * it back, so another site cannot sign somebody in as itself.
 *
 * Each provider app has `https://hub.coilbox.org/auth/<provider>/callback`
 * registered, and its id and secret in `<PROVIDER>_CLIENT_ID` and
 * `<PROVIDER>_CLIENT_SECRET`.
 */
final class HubSignIn
{
    private const CLASSES = [
        'discord' => DiscordProvider::class,
        'github' => GithubProvider::class,
        'twitch' => TwitchProvider::class,
    ];

    /** The session key for a sign in on its way to a provider. */
    private const PENDING = 'oauth';

    public static function callbackUrl(string $provider): string
    {
        return Site::absolute('/auth/'.$provider.'/callback');
    }

    public static function configured(string $provider): bool
    {
        return (string) config("services.{$provider}.client_id") !== ''
            && (string) config("services.{$provider}.client_secret") !== '';
    }

    /**
     * The providers to offer, id to label, in the order of
     * {@see GoTrue::PROVIDERS}. A provider whose app is not set up here is
     * left out, so nobody is shown a button that cannot work.
     *
     * @return array<string, string>
     */
    public static function offered(): array
    {
        return array_filter(GoTrue::PROVIDERS, fn (string $id) => self::configured($id), ARRAY_FILTER_USE_KEY);
    }

    public static function driver(string $provider): AbstractProvider
    {
        $config = [
            'client_id' => (string) config("services.{$provider}.client_id"),
            'client_secret' => (string) config("services.{$provider}.client_secret"),
            'redirect' => self::callbackUrl($provider),
        ];
        // The tests answer for the providers here.
        if (app()->bound('hub.oauth.guzzle')) {
            $config['guzzle'] = app('hub.oauth.guzzle');
        }

        return Socialite::buildProvider(self::CLASSES[$provider], $config);
    }

    /**
     * Sends the browser to the provider. `$linkTo` is the signed in account a
     * link adds the provider to, or null for a sign in.
     */
    public static function redirect(string $provider, string $next, ?string $linkTo): RedirectResponse
    {
        session()->put(self::PENDING, ['provider' => $provider, 'next' => $next, 'link' => $linkTo]);

        return redirect()->away(self::driver($provider)->redirect()->getTargetUrl(), 303);
    }

    /**
     * The sign in this browser started with this provider, taken out of the
     * session so it is only used once.
     *
     * @return array{provider: string, next: string, link: ?string}|null
     */
    public static function pending(string $provider): ?array
    {
        $pending = session()->pull(self::PENDING);

        return is_array($pending) && ($pending['provider'] ?? null) === $provider ? $pending : null;
    }
}
