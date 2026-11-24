# Google sign in

People can sign in to the hub with Google, on the website and in the Coilbox app, alongside Discord, GitHub and Twitch.

## Decisions

- Google works only on the hub's own sign in route, when `SignInStore` is `mariadb`. It is not added to Supabase.
- Google is not tested locally. The manual check happens on `hub.coilbox.org`.
- The hub only offers a provider it can sign in with. This applies to every provider, not only Google.
- A Google account joins an existing hub account by email only when Google says the address is verified. This is the same rule as the other providers.

## Google setup (done)

- Google Cloud project with the Google Auth Platform set up, audience External, publishing status Testing.
- Web application client with `https://hub.coilbox.org/auth/google/callback` as a redirect address.
- `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` are in the production `.env`.
- The owner's Google account is a test user. While the app is in Testing mode, only test users can sign in with Google.

Opening Google sign in to everyone means publishing the app in Google Cloud, which needs a privacy policy page. The hub has none. That is separate work.

## Which providers are offered

A new method, `HubSignIn::offered()`, answers the providers in `GoTrue::PROVIDERS` whose client ID and secret are both set (`HubSignIn::configured()`), in that order.

Every account has been copied out of Supabase and production signs in on the hub's route, so the rule does not look at `SignInStore`. If sign in were rolled back to Supabase, Google would be offered there and fail, which is accepted.

These read from it instead of `GoTrue::PROVIDERS`:

- the sign in buttons, `resources/views/components/ui/sign-in-buttons.blade.php`
- `providers` in `/api/v1/auth`, `app/Domain/Assets/AuthDiscovery.php`
- the providers the account page offers to link, `AccountRules.php:83`

`UserCopy.php:119`, which copied accounts out of Supabase, keeps looping over `GoTrue::PROVIDERS`. Supabase has no Google identities, so Google changes nothing there.

`GoTrue::PROVIDERS` stays the full list of providers the hub knows, with their display names. Callbacks and unlinking still accept any provider in it, so someone with a Google identity can still see and unlink it if Google is later switched off.

If an offered list is empty, the sign in page shows no buttons. That only happens when no provider is set up, and needs no special message.

## Hub changes (`hub-php/`)

1. `GoTrue::PROVIDERS` gains `'google' => 'Google'`.
2. `HubSignIn::CLASSES` gains `'google' => GoogleProvider::class`, which ships with Laravel Socialite. No new package.
3. `config/services.php` gains a `google` entry read from `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`.
4. `HubAccounts::profile` gains Google lines:
   - `verified` is true only when the raw answer's `email_verified` is `true`.
   - `identity_data` gets `iss` `https://accounts.google.com`, `name` and `full_name` from the Google name, with `sub`, `avatar_url`, `picture` and `email` filled by the shared code as for the others.
5. `HubSignIn::offered()` as above, used by the places listed.
6. The sign in button for Google has a white background, dark text and the multicoloured "G", as Google's branding rules require. The "G" goes in `resources/views/components/provider-logo.blade.php`.
7. The example on the developers page, `DevelopersController.php:132`, lists `google` as well.

## Coilbox app change (`~/dev/coilbox`)

The app builds its buttons from the hub's `/api/v1/auth` list, so it offers Google with no change to sign in itself. Without a look for Google it would show a plain button, so `src/hub/pages/components/ProviderLogo.tsx` gains a `google` entry with the same white button and "G" as the hub. This is a separate PR in that repo.

## Tests

Hub feature tests, following the existing provider tests and the faked Guzzle client bound at `hub.oauth.guzzle`:

- A Google sign in creates an account with the Google identity and its `identity_data`.
- A Google account with a verified email joins the existing account with that confirmed email.
- A Google account with an unverified email gets a new account and does not join.
- Linking Google from the account page adds it to the signed in account.
- With Google's ID or secret missing, the sign in page and `/api/v1/auth` leave Google out. The same holds for an existing provider, to cover the change of rule.

App tests in `ProviderLogo` or the existing `AccountControl` and `hubPage` tests, checking the Google button's name and look.

## Shipping

1. Merge and deploy the hub change. Google appears at once, because the secrets are already set.
2. The owner signs in with Google on `hub.coilbox.org` as a test user, then links and unlinks Google from `/account`.
3. Merge the app change. Older app versions still show Google, with a plain button.
