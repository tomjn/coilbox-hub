# Mirror self-registration: design

Status: draft for review, 2026-11-23.

Builds on `2026-11-16-hub-mirrors-design.md`.

## Purpose

Setting up a mirror today means editing files to swap keys. The operator runs `bin/keygen.php`, pastes the secret key into `private/config.php`, sends the public key to the hub operator, and pastes the hub's public key back into `hub_keys`. A moderator then types the mirror's details into the add form.

After this change an operator uploads the mirror, opens `setup.php` in a browser, and follows a link to the hub to ask for the mirror to be added. A moderator approves the request. Nobody copies a key by hand.

## What changes

- The mirror ships with the production hub's public key, so `hub_keys` only changes for a different hub.
- The hub publishes its public key.
- The mirror makes its own key pair and writes its own config.
- A signed-in hub user asks for a mirror to be added. A moderator approves or rejects the request in `/moderation/mirrors`.
- The hub can ask a mirror to prove it holds the key the hub has on record. It does this when a request arrives, again on approval, and on demand for existing mirrors.

The manual add form in `/moderation/mirrors` stays as a fallback.

## Mirror (`coilbox-mirror`)

### `setup.php`

A web page, reached at `{base}/setup.php`.

1. If `private/config.php` exists, the page says the mirror is already set up and shows the registration link from step 5. It changes nothing.
2. If `private/` is not writable by PHP, the page says so and how to fix it, then stops.
3. Otherwise it shows a form with:
   - mirror ID, checked with the hub's existing rule: lowercase letters, digits and hyphens, at most 64 characters, starting with a letter or digit
   - display name, 1 to 128 characters
   - base URL, filled in from the scheme, host and folder of the current request, must be `https://`
4. On submit it makes an Ed25519 key pair and writes `private/config.php`, with `mirror_id`, `mirror_name`, `secret_key` and `base_url` from the form, and `hub_url` and `hub_keys` set to the production hub's URL and key from `src/HubDefaults.php`. The write fails if the file appeared since step 1, so two visitors cannot both set it up.
5. It shows the registration link: `{hub_url}/mirrors/new?id=…&name=…&url=…&key=…`, with the public key in base64url.

The page has no hub field. Anyone who opens the page first must not be able to choose which key the mirror trusts. An operator who wants a different hub edits `hub_url` and `hub_keys` in `private/config.php` after setup, as the README explains.

The secret key is never shown on the page or sent anywhere.

The page is one-shot because anyone can open it before the operator does. If someone else got there first, the operator sees "already set up" with details they did not choose. The README tells them to delete `private/config.php` and open the page again.

### Input handling in `setup.php`

- Validate every field before writing anything, and show the form again with the reason on failure:
  - ID: the hub's rule above.
  - Name: 1 to 128 characters, valid UTF-8, no control or format characters.
  - Base URL: parses with `parse_url`, scheme `https`, has a host, no user, password, query or fragment. Trailing slash removed.
- Write `private/config.php` with `var_export` for each value, never by joining strings, then check the file loads and returns the expected array before reporting success.
- Escape everything printed on the page with `htmlspecialchars`.
- Build the registration link with `http_build_query`.

### `identity.php`

`GET {base}/identity.php?challenge=<base64url 32 bytes>` returns:

```json
{"mirror_id": "…", "base_url": "…", "public_key": "…", "sig": "…"}
```

`sig` is the mirror's signature over `"coilbox-mirror/identity/v1\n" . challenge . base_url`, both raw. Without `challenge` the response leaves out `sig`. Before setup it returns 404.

Including `base_url` in the signed bytes stops a mirror from replaying another mirror's answer under a different address.

### Other mirror changes

- `src/Config.php` reads two new optional keys, `base_url` (`https://`, no trailing slash) and `mirror_name`. They are optional so configs written before this change keep loading. `identity.php` answers 404 until `base_url` is set, and the README tells existing operators to add it.
- `bin/register.php` prints the registration link again. It needs `base_url` and `mirror_name`.
- `bin/check.php` fetches `{hub_url}/api/v1/mirrors/hub-key` and warns when the published key is missing from `hub_keys`.
- When the hub answers a report with "unknown mirror", `sync.php` prints `not approved by the hub yet` in place of `sync report failed`.
- The root `.htaccess` lets visitors reach `setup.php` and `identity.php`. The nginx example in the README does the same.
- `bin/keygen.php` stays, because the hub's deploy README uses it to make the hub's own key. The README's setup steps become: upload the files, open `setup.php`, follow the link, wait for approval, add the cron job.
- `PROTOCOL.md` documents `identity.php`, the identity signing context and the `hub-key` response.

## Hub (`hub-php`)

### `GET /api/v1/mirrors/hub-key`

Public. Returns:

```json
{"public_key": "…", "next": {"public_key": "…", "sig": "…"}}
```

`next` is present only while a rotation is under way, taken from `HubKeys::nextKey()`. Returns 503 when no hub key is configured.

### `GET /mirrors/new` and `POST /mirrors/new`

A page for signed-in users. Visitors who are not signed in go to sign-in and come back to the same link.

The GET shows the details from the query string for the user to confirm. The POST:

1. Checks the ID, name, URL and key with the same rules as `Registry::add`. The hub does not trust the link's values because the mirror checked them. Anyone can edit the link. The page shows them through Blade's escaped output, and the form is CSRF protected like the hub's other forms.
2. Refuses an ID or key already used by a mirror.
3. Runs the identity check below.
4. Stores or replaces the `mirror_request` row for that public key.
5. Tells the user a moderator will review it.

Each failure has its own message, as `Registry` does today.

### Identity check

`App\Domain\Mirrors\Identity::check(string $baseUrl, string $mirrorId, string $publicKey)` returns a result naming what failed:

1. Refuse a URL that is not `https://`, or whose host resolves to a private, loopback, link-local or reserved address. A signed-in user chooses this URL, so without this check the hub could be made to fetch internal addresses.
2. Make a random 32-byte challenge.
3. Fetch `{base_url}/identity.php?challenge=…` without following redirects, the way `HealthCheck` does. The request is pinned to the address checked in step 1, so a DNS answer that changes between the check and the fetch cannot send it elsewhere.
4. Check that `mirror_id`, `base_url` and `public_key` match, and that `sig` verifies against `public_key`.

### `mirror_request` table (MariaDB)

| Column | Notes |
|---|---|
| `public_key` | primary key, 43 characters |
| `mirror_id` | 64 characters |
| `name` | 128 characters |
| `base_url` | text |
| `requested_by` | the hub user's ID |
| `requested_at` | datetime |
| `checked_at` | when the identity check last passed |

The table goes in MariaDB even while `mirror` itself may still be read from Supabase. Approval writes the mirror through `Registry::add`, which already handles both stores.

### `/moderation/mirrors`

- A "Requests" list above the mirrors, showing each request's ID, name, URL, key, requesting user and time.
- Approve: runs the identity check again, calls `Registry::add`, deletes the request.
- Reject: deletes the request.
- Each existing mirror gets "Check key", which runs the identity check against the stored ID, URL and key and shows the result.

## Errors

| What happens | What the person sees |
|---|---|
| `setup.php` opened after setup | "Already set up", with the registration link |
| `private/` not writable | How to make it writable, then reload |
| A field fails validation | The form again, naming the field and the rule, with nothing written |
| Mirror unreachable, wrong key, bad signature or private address | The hub names the failed check and stores nothing |
| ID or key already in use | The hub says which, and stores nothing |
| Identity check fails on approval | The moderator sees why. The request stays |
| Reports sent before approval | `sync.php` prints `not approved by the hub yet` |

## Testing

- Mirror: PHPUnit tests for `setup.php` (writes config once, refuses a second run, refuses a non-writable folder, base URL taken from the request, each validation rule, a name holding PHP and quote characters writes a config that loads with the name unchanged, output escaped), `identity.php` (signature verifies, includes `base_url`, 404 before setup), the new `Config` key and the `sync.php` message.
- Hub: PHPUnit tests with `Http::fake()` for `hub-key`, the request page (signed out, bad input, ID taken, each identity check failure, success), approve, reject and "Check key". The private address check is tested with hosts that resolve to loopback and private ranges.
- End to end: run a mirror on PHP's built-in server, set it up through `setup.php`, request it on a local hub, approve it, and confirm its next sync report is accepted.

## Build order

1. Hub: `hub-key` endpoint, identity check, `mirror_request` table, request page, moderation list.
2. Deploy the hub, then read the production key from `https://hub.coilbox.org/api/v1/mirrors/hub-key`.
3. Mirror: `identity.php`, `setup.php` with that key, the other mirror changes and the README.

The hub's request page needs `identity.php`, so test step 1 against a local mirror on the step 3 branch before deploying.

## Risks

- A visitor who reaches `setup.php` before the operator controls the mirror's key until the operator notices and redoes setup. The hub never adds a mirror without a moderator's approval, and the moderator sees who asked.
- Shipping the hub key in the mirror code means a hub key rotation also needs a mirror release for new installs. Existing mirrors still learn new keys from the signed catalogue.
