# Google sign in implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** People can sign in to the hub with Google, and the hub only offers providers whose ID and secret are set.

**Architecture:** Google joins the existing Socialite route through `GoTrue::PROVIDERS` and `HubSignIn::CLASSES`. A new `HubSignIn::offered()` filters the provider list by configuration and replaces `GoTrue::PROVIDERS` wherever a provider is offered. The Coilbox app reads the hub's list already and only needs Google's look.

**Tech stack:** Laravel 12 with Socialite's built in `GoogleProvider`, PHPUnit, Blade. The app is React and TypeScript with Vitest.

**Spec:** `docs/superpowers/specs/2026-11-23-google-sign-in-design.md`

## Global constraints

- No new package in either repo.
- Google's button: white background, text `#1f1f1f`, 1px border `#747775`, and the four colour "G". Label `Sign in with Google`.
- Google's identity `iss` is `https://accounts.google.com`.
- A Google email is verified only when the raw `email_verified` is exactly `true`.
- Run hub commands from `hub-php/`, app commands from `~/dev/coilbox`.

## Review focus

1. Google sends `email_verified: false` for an address that matches an existing account. Expected: a new account, never a join. Covered in task 2.
2. A provider's secret is removed while someone has that provider linked. Expected: the account page still lists and can unlink it, but does not offer it for linking. Covered in task 1.
3. Coilbox asks `/api/v1/auth/authorize` for a provider that is not set up. Expected: the existing 400 refusal. Already covered by `CoilboxSignIn::refuse`, task 2 adds Google to the token test data so a set up Google is accepted.
4. Google answers with no `name`. Expected: sign in still works and the display name falls back as for other providers. Covered in task 2.
5. Google's `picture` is missing. Expected: no `avatar_url` key, not an empty string. Covered by the existing `array_filter` in `profile()`, checked in task 2.

---

### Task 1: Offer only providers that are set up

**Files:**
- Modify: `hub-php/app/Auth/HubSignIn.php`
- Modify: `hub-php/resources/views/components/ui/sign-in-buttons.blade.php:13`
- Modify: `hub-php/app/Domain/Assets/AuthDiscovery.php:50`
- Modify: `hub-php/app/Domain/Account/AccountRules.php:83`
- Test: `hub-php/tests/Feature/HubSignInTest.php`, `hub-php/tests/Feature/Assets/AssetRoutesTest.php`

**Interfaces:**
- Produces: `HubSignIn::offered(): array<string, string>`, provider id to label, `GoTrue::PROVIDERS` filtered by `HubSignIn::configured()`, order kept.

- [ ] **Step 1: Write the failing tests**

In `HubSignInTest`:

```php
public function test_a_provider_without_its_app_set_up_is_not_offered(): void
{
    config(['services.twitch' => ['client_id' => null, 'client_secret' => null]]);

    $this->get('/sign-in')->assertOk()->assertSee('Sign in with GitHub')->assertDontSee('Sign in with Twitch');
    $this->getJson('/api/v1/auth')->assertJsonPath('providers', ['discord', 'github']);
}

public function test_a_linked_provider_that_is_no_longer_set_up_can_still_be_unlinked_but_not_linked_again(): void
{
    $this->account(self::ALICE, 'alice@example.test', [['github', '1'], ['twitch', '2']]);
    DB::table('profile')->insert(['user_id' => self::ALICE, 'display_name' => 'Alice', 'created_at' => now(), 'updated_at' => now()]);
    config(['services.twitch' => ['client_id' => null, 'client_secret' => null], 'services.discord' => ['client_id' => null, 'client_secret' => null]]);

    $this->asAlice()->get('/account')->assertOk()->assertSee('Twitch')->assertDontSee('Discord');
}
```

The `/api/v1/auth` call needs `hub.supabase.url` and `hub.supabase.publishable_key` set in that test, as `AssetRoutesTest` does. Check that `/account` renders "Discord" nowhere else before trusting `assertDontSee('Discord')`. If it does, assert on the link form's hidden `provider` value instead.

- [ ] **Step 2: Run them and see them fail**

Run: `php artisan test --filter='not_offered|no_longer_set_up'`
Expected: both FAIL, Twitch and Discord still shown.

- [ ] **Step 3: Add `HubSignIn::offered()` and use it in the three places**

`sign-in-buttons.blade.php` loops over `\App\Auth\HubSignIn::offered()`. `AuthDiscovery` uses `array_keys(HubSignIn::offered())`. `AccountRules::linkableProviders` filters `HubSignIn::offered()` instead of `GoTrue::PROVIDERS`. Leave the label lookups in `AccountRules` and `SignInController` on `GoTrue::PROVIDERS`.

- [ ] **Step 4: Fix the discovery test's setup**

`AssetRoutesTest::test_auth_discovery` expects `['discord', 'github', 'twitch']`. If `phpunit.xml` does not set the provider secrets, add the three `services.*` entries to that test's config, matching `HubSignInTest::setUp`.

- [ ] **Step 5: Run the hub tests**

Run: `php artisan test`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add hub-php/app/Auth/HubSignIn.php hub-php/resources/views/components/ui/sign-in-buttons.blade.php hub-php/app/Domain/Assets/AuthDiscovery.php hub-php/app/Domain/Account/AccountRules.php hub-php/tests
git commit -m "Only offer sign in providers whose app is set up"
```

### Task 2: Sign in with Google

**Files:**
- Modify: `hub-php/app/Auth/GoTrue.php:23`
- Modify: `hub-php/app/Auth/HubSignIn.php` (`CLASSES`)
- Modify: `hub-php/config/services.php`
- Modify: `hub-php/app/Auth/HubAccounts.php:40-68`
- Modify: `hub-php/app/Http/Controllers/Gallery/DevelopersController.php:132`
- Test: `hub-php/tests/Feature/HubSignInTest.php`, `hub-php/tests/Feature/Assets/HubTokensTest.php`

**Interfaces:**
- Consumes: `HubSignIn::offered()` from task 1.
- Produces: provider id `google`, label `Google`.

- [ ] **Step 1: Extend the test data**

In both test classes, add `'services.google' => ['client_id' => 'google-id', 'client_secret' => 'google-secret']` to `setUp`, and add Google to the data providers:

```php
'google' => ['google'],                                                // HubSignInTest::providers
'google' => ['google', 'https://accounts.google.com/o/oauth2/auth'],   // authorizeUrls, and HubTokensTest::providers
```

In `HubSignInTest::fakeProvider`, add:

```php
'google' => [$token,
    self::reply(array_filter([
        'sub' => self::PROVIDER_ID, 'name' => 'Pat Person', 'picture' => 'https://lh3.example.test/p',
        'email' => $email, 'email_verified' => $person['verified'] ?? true,
    ], fn ($v) => $v !== null)),
],
```

Check whether `HubTokensTest` has its own fake provider helper. If it does, add the same Google answer there.

- [ ] **Step 2: Write the Google specific tests**

```php
public function test_an_unverified_google_email_does_not_join_an_account(): void
{
    $this->account(self::ALICE, 'alice@example.test', [['github', '1']]);

    $this->signInWith('google', ['email' => 'alice@example.test', 'verified' => false])->assertRedirect('/');

    $this->assertNotSame(self::ALICE, session('hub.user_id'));
    $this->assertSame(2, DB::table('auth_user')->count());
    $this->assertNull(DB::table('auth_user')->where('id', session('hub.user_id'))->value('email_confirmed_at'));
}

public function test_a_google_identity_keeps_the_names_and_picture_supabase_would_have(): void
{
    $this->signInWith('google', ['email' => 'new@example.test'])->assertRedirect('/');

    $data = json_decode(DB::table('auth_identity')->where('provider', 'google')->value('identity_data'), true);
    $this->assertSame('https://accounts.google.com', $data['iss']);
    $this->assertSame('Pat Person', $data['name']);
    $this->assertSame('Pat Person', $data['full_name']);
    $this->assertSame('https://lh3.example.test/p', $data['avatar_url']);
    $this->assertTrue($data['email_verified']);
}
```

Also run the linking test with Google: a copy of `test_linking_adds_a_provider_to_the_signed_in_account` with `google` in place of `twitch`, named `test_linking_adds_google_to_the_signed_in_account`.

- [ ] **Step 3: Run the tests and see them fail**

Run: `php artisan test --filter='HubSignInTest|HubTokensTest'`
Expected: Google cases FAIL with "Unknown sign in provider" or an undefined `CLASSES` key.

- [ ] **Step 4: Add Google**

- `GoTrue::PROVIDERS` gains `'google' => 'Google'` after `'github'`, so the list stays alphabetical.
- `HubSignIn::CLASSES` gains `'google' => GoogleProvider::class` (`Laravel\Socialite\Two\GoogleProvider`).
- `config/services.php` gains `'google'` reading `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`.
- `HubAccounts::profile`: the `verified` match gains `'google' => ($raw['email_verified'] ?? false) === true`. The `data` match gains `'google' => ['iss' => 'https://accounts.google.com', 'name' => $user->getName(), 'full_name' => $user->getName()]`. Update the comment above `verified` to say Google says, like Discord.
- `DevelopersController.php:132` example becomes `['discord', 'github', 'google', 'twitch']`.

- [ ] **Step 5: Run the hub tests**

Run: `php artisan test`
Expected: all pass. `test_auth_discovery` expects the list without Google unless its config sets Google's secrets. Leave it without Google.

- [ ] **Step 6: Commit**

```bash
git add hub-php/app hub-php/config/services.php hub-php/tests
git commit -m "Sign in with Google"
```

### Task 3: Google's button on the hub

**Files:**
- Modify: `hub-php/resources/views/components/provider-logo.blade.php`
- Modify: `hub-php/resources/views/components/ui/sign-in-buttons.blade.php`
- Test: `hub-php/tests/Feature/HubSignInTest.php`

**Interfaces:**
- Consumes: provider id `google` from task 2.

- [ ] **Step 1: Write the failing test**

```php
public function test_the_google_button_has_googles_mark_on_white(): void
{
    $this->get('/sign-in')->assertOk()
        ->assertSee('Sign in with Google')
        ->assertSee('fill="#4285F4"', false)
        ->assertSee('bg-white', false);
}
```

- [ ] **Step 2: Run it and see it fail**

Run: `php artisan test --filter=googles_mark`
Expected: FAIL. With no `google` path the logo component throws an undefined key first, which also counts.

- [ ] **Step 3: Add the mark and colours**

`provider-logo.blade.php` keeps the single path marks and adds a Google branch rendering this SVG, with `aria-hidden="true"` and the same merged `size-4` class:

```html
<svg viewBox="0 0 48 48" aria-hidden="true">
  <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
  <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
  <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
  <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
</svg>
```

`sign-in-buttons.blade.php` gains `'google' => 'border border-[#747775] bg-white !text-[#1f1f1f] hover:bg-[#f2f2f2] active:bg-[#e8e8e8]'`. The shared `$button` sets `text-white`, so either move `text-white` into each of the other three colour strings or use the `!` override shown. Prefer moving it.

Check `resources/views/auth/account.blade.php:63` shows the Google mark next to a linked Google identity. It uses the same component, so it should need no change.

- [ ] **Step 4: Run the hub tests and build the CSS**

Run: `php artisan test` then `npm run build` in `hub-php/`.
Expected: all pass, and the build emits the new Tailwind classes without errors.

- [ ] **Step 5: Commit**

```bash
git add hub-php/resources/views/components hub-php/tests/Feature/HubSignInTest.php
git commit -m "Give the Google sign in button Google's mark on white"
```

### Task 4: Google's button in the Coilbox app (`~/dev/coilbox`, separate branch and PR)

**Files:**
- Modify: `src/hub/pages/components/ProviderLogo.tsx`
- Modify: `src/hub/pages/components/AccountControl.tsx:258` only if the text colour needs it
- Test: `src/hub/pages/components/AccountControl.test.ts` or `src/onboarding/pages/hubPage.dom.test.tsx`, whichever already renders buttons from a provider list

**Interfaces:**
- Consumes: the hub listing `google` in `/api/v1/auth`.

- [ ] **Step 1: Write the failing test**

In `hubPage.dom.test.tsx`, add `"google"` to the faked `hubProviders` list and assert:

```tsx
const google = await screen.findByRole("button", { name: "Sign in with Google" });
expect(google.className).toContain("bg-white");
expect(google.querySelector('path[fill="#4285F4"]')).not.toBeNull();
```

- [ ] **Step 2: Run it and see it fail**

Run: `bun run test src/onboarding/pages/hubPage.dom.test.tsx`
Expected: FAIL. The button exists with name `Sign in with google`, unstyled.

- [ ] **Step 3: Add Google's look**

`ProviderLook.path: string` becomes `mark: string | { viewBox: string; paths: { d: string; fill: string }[] }`. A string keeps rendering as today, as one `currentColor` path on `0 0 24 24`. The Google entry uses the four paths from task 3 on `0 0 48 48`. Its `colours` are the same string as the hub's. `AccountControl` adds `text-white` before `colours` through `cn`, and tailwind-merge lets the later `text-[#1f1f1f]` win, so check that and only touch `AccountControl.tsx` if it does not. Update the file's header comment, which names the Next app's `SignInButtons.tsx`, to point at `hub-php/resources/views/components/ui/sign-in-buttons.blade.php`.

- [ ] **Step 4: Run the app checks**

Run: `bun run test` then `bunx biome check src/hub src/onboarding` then `bunx tsc --noEmit`.
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add src/hub/pages/components/ProviderLogo.tsx src/onboarding/pages/hubPage.dom.test.tsx
git commit -m "Show Google's mark on its sign in button"
```

## After the tasks

1. Hub PR from `feat/google-sign-in`, app PR from a new branch in `~/dev/coilbox`.
2. After the hub deploys, the owner signs in with Google on `hub.coilbox.org`, then links and unlinks Google from `/account`.
