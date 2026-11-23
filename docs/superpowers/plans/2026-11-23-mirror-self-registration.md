# Mirror self-registration implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mirrors set themselves up in a browser and ask to join. A moderator approves them after the hub proves each mirror holds its key.

**Architecture:** The hub adds a public `hub-key` endpoint, an identity check that challenges a mirror's `identity.php`, a `mirror_request` table, a signed-in request page and moderation controls. The mirror adds `setup.php`, `identity.php`, `bin/register.php`, two optional config keys and a clearer sync message.

**Tech Stack:** Laravel 12 on PHP 8.3 and 8.4, MariaDB, sodium. The mirror is plain PHP 8.4 with PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-23-mirror-self-registration-design.md`

## Global constraints

- Identity signing context: `"coilbox-mirror/identity/v1\n"`. The signed message is the raw 32-byte challenge followed by the base URL.
- Mirror ID rule: `/\A[a-z0-9][a-z0-9-]{0,63}\z/`. Name: 1 to 128 characters. Key: `/\A[A-Za-z0-9_-]{43}\z/`.
- Base URL: `https`, has a host, no user, password, query or fragment, no trailing slash.
- The hub never follows redirects when it fetches from a mirror.
- Comments and Markdown: no semicolons or em dashes.

## Review focus

1. A name holding quotes, `?>` or `<script>` must round-trip through `config.php` and every page unchanged and unexecuted. Tests: `SetupTest::test_hostile_name_round_trips`, `MirrorRequestPageTest::test_values_are_escaped`.
2. A base URL whose host resolves to `127.0.0.1`, `10.x`, `169.254.x` or `::1` must be refused before any request. Test: `IdentityTest::test_private_addresses_are_refused`.
3. Two visitors posting `setup.php` at once: only one config is written. Test: `SetupTest::test_write_refuses_existing_file`.
4. A mirror answering with another mirror's signature, made for a different base URL, must fail. Test: `IdentityTest::test_signature_for_another_url_fails`.
5. A moderator approving a request whose mirror has since gone offline: the request stays and the moderator sees why. Test: `MirrorRequestsTest::test_approve_rechecks_identity`.

---

## Hub (`coilbox-hub`, branch `feat/mirror-self-registration`)

### Task H1: `GET /api/v1/mirrors/hub-key`

**Files:** Create `app/Http/Controllers/Mirrors/HubKeyController.php`. Modify `routes/api/mirrors.php`. Test `tests/Feature/Mirrors/HubKeyApiTest.php`.

- [ ] Tests: returns `{"public_key"}` from `HUB_MIRROR_SECRET_KEY`, adds `next` with `public_key` and `sig` when a next key is set, and returns 503 when no key is set.
- [ ] `HubKeyController::show()` uses `HubKeys::fromConfig()` and `Api::json`. Add an OPTIONS preflight like the catalogue's.
- [ ] Run `php artisan test --filter=HubKeyApiTest`, then commit.

### Task H2: Identity check

**Files:** Create `app/Domain/Mirrors/Identity.php`. Modify `Signing.php` to add `CTX_IDENTITY`. Test `tests/Unit/Mirrors/IdentityTest.php`.

**Produces:** `new Identity(?Closure $resolve = null)` and `->check(string $baseUrl, string $mirrorId, string $publicKey): ?string`. It returns null on success, or one of `bad_url`, `private_address`, `unreachable`, `bad_answer`, `wrong_id`, `wrong_url`, `wrong_key` or `bad_signature`. `Identity::MESSAGES` maps each code to a sentence. `$resolve` is `fn (string $host): list<string>` and returns IP addresses. It defaults to A and AAAA lookups.

- [ ] Tests with `Http::fake()` and a fake resolver: success, each failure code, `test_private_addresses_are_refused` (for `127.0.0.1`, `10.0.0.1`, `169.254.169.254`, `::1` and a host with no addresses, nothing is sent), `test_signature_for_another_url_fails`, and redirects are not followed.
- [ ] Implement. Use `filter_var` with `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`. Every resolved address must pass. Pin the fetch with `CURLOPT_RESOLVE` set to `host:port:ip`.
- [ ] Run the tests, then commit.

### Task H3: `mirror_request` table and `MirrorRequests`

**Files:** Create a migration and `app/Domain/Mirrors/MirrorRequests.php`. Test `tests/Feature/Mirrors/MirrorRequestsTest.php`.

**Produces:**
- `MirrorRequests::submit(string $userId, string $id, string $name, string $url, string $key, Identity $identity): array{ok: bool, message: string}`
- `pending(): list<array>`, newest first, with the requester's display name where known
- `approve(Client $moderator, string $key, Identity $identity): array{ok, message}`
- `reject(string $key): array{ok, message}`
- `checkMirror(array $mirror, Identity $identity): array{ok, message}`

- [ ] Tests:
  - validation messages
  - refuses an ID or key a mirror already uses
  - a failed identity check stores nothing
  - a repeat request for one key replaces the earlier row
  - `test_approve_rechecks_identity`: a failure keeps the row
  - approve calls `moderation_add_mirror` and deletes the row
  - reject deletes the row
- [ ] Migration with the spec's columns. `public_key` is the primary key.
- [ ] Implement. Approve goes through `Registry::add`. The callers check moderator access before calling `approve` and `reject`.
- [ ] Run the tests, then commit.

### Task H4: `/mirrors/new`

**Files:** Create `routes/web/mirrors.php`, `app/Http/Controllers/Mirrors/RequestController.php` and `resources/views/mirrors/new.blade.php`. Test `tests/Feature/Mirrors/MirrorRequestPageTest.php`.

- [ ] Tests:
  - signed out redirects to `/sign-in?next=` with the full link
  - signed in shows the prefilled values
  - `test_values_are_escaped`
  - POST success shows the confirmation
  - POST failure shows the reason and stores nothing
- [ ] Implement. The page is a thin controller over `MirrorRequests::submit`.
- [ ] Run the tests, then commit.

### Task H5: Moderation controls

**Files:** Modify `MirrorsController.php`, `routes/web/moderation.php` and `resources/views/moderation/mirrors.blade.php`. Test `tests/Feature/Moderation/MirrorsTest.php`.

- [ ] Tests:
  - requests are listed with the requester
  - approve, reject and check key each redirect with the message
  - a non-moderator gets 404 and nothing changes
- [ ] Add `POST /moderation/mirrors/requests/approve`, `.../requests/reject` and `/moderation/mirrors/check`. Change the add form's copy to point operators at `setup.php`.
- [ ] Update `deploy/README.md` mirror steps. Run the full suite with `php artisan test` and `vendor/bin/pint --test`, then commit.

## Mirror (`coilbox-mirror`, branch `feat/self-registration`)

### Task M1: Config keys and `identity.php`

**Files:** Modify `src/Config.php` and `src/Crypto.php`. Create `src/Identity.php` and `identity.php`. Test `tests/Unit/ConfigTest.php` and `tests/Unit/IdentityTest.php`.

**Produces:** `Config->baseUrl: ?string`, `Config->mirrorName: ?string`, `Crypto::CTX_IDENTITY`, and `Identity::respond(?Config $c, mixed $challenge): Response`. The response is 404 without a config or `base_url`, 400 for a challenge that is not base64url of 32 bytes, and otherwise 200 with `mirror_id`, `base_url`, `public_key` and `sig`.

- [ ] Tests for each case, plus: the signature verifies over challenge and base URL.
- [ ] Implement, then run `vendor/bin/phpunit`, then commit.

### Task M2: `setup.php`

**Files:** Create `src/HubDefaults.php`, `src/Setup.php` and `setup.php`. Test `tests/Unit/SetupTest.php`.

**Produces:**
- `Setup::validate(array $input): array{values: array, errors: array<string,string>}`
- `Setup::baseUrlFromServer(array $server): string`
- `Setup::configSource(array $values, string $secretB64): string`
- `Setup::write(Paths $p, string $source): bool`, which creates the file with `fopen(..., 'x')`
- `Setup::registrationLink(Config $c): string`

- [ ] Tests:
  - each validation rule
  - `test_hostile_name_round_trips`
  - `test_write_refuses_existing_file`
  - the written config loads through `Config::load`
  - the base URL comes from `HTTPS`, `HTTP_HOST` and the script's folder
  - the link is built with `http_build_query`
- [ ] Implement `setup.php` as a plain HTML page using `htmlspecialchars` for all output. It has the states already set up, not writable, form, and done.
- [ ] Run the tests, then commit.

### Task M3: `bin/register.php`, `bin/check.php` and the sync message

**Files:** Create `bin/register.php`. Modify `bin/check.php`, `src/Report.php` and `src/Sync.php`. Tests in `tests/Unit/ReportTest.php` and `tests/Unit/SyncRoutineTest.php`.

- [ ] `Report::post(...): HttpResponse`. `send` stays a bool wrapper. `Report::unknownMirror(HttpResponse): bool` is true for 403 with `{"error":"unknown_mirror"}`. `Sync::report` logs `sync report failed: not approved by the hub yet` in that case.
- [ ] `check.php` fetches `{hub_url}/api/v1/mirrors/hub-key` when config loads, and adds a problem when the key is not in `hub_keys`.
- [ ] Run the tests, then commit.

### Task M4: Web server rules and docs

**Files:** Modify `.htaccess`, `README.md`, `PROTOCOL.md` and `private/config.sample.php`.

- [ ] `.htaccess`: let visitors reach `setup.php` and `identity.php`. Update the nginx example to match.
- [ ] README: the setup steps become uploading the files, opening `setup.php`, following the link, waiting for approval and adding the cron job. Explain how existing mirrors add `base_url` and `mirror_name`, and how to use a custom hub.
- [ ] PROTOCOL: `identity.php`, the identity signing context and the `hub-key` response.
- [ ] Commit.

### Task M5: Production key

After the hub PR deploys, read `https://hub.coilbox.org/api/v1/mirrors/hub-key` and put its `public_key` in `HubDefaults::PUBLIC_KEY`. Add a test that checks the key decodes to 32 bytes. Commit.

## Release

1. Push the hub branch, open a PR, merge when CI passes, and wait for the deploy.
2. Do task M5, then push the mirror branch, open a PR and merge when CI passes.
