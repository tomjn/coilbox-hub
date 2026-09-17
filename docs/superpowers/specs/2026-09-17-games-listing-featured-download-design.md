# Games listing: featured games, download sources, card art

Seven changes to the games catalog, landing as one branch of atomic commits.

1. Order the listing alphabetically instead of by unit count.
2. Let a moderator feature a game, which pins it to the top.
3. Draw a divider between featured games and the rest.
4. Give a game a download source a lobby can act on: a rapid tag, a URL, or a GitHub repo with releases.
5. Add a third game picture, 16:9 card art, beside the logo and banner.
6. Make the edit link on a game page a button, and stop the hide button spanning the column.
7. Replace the near-black card background app-wide with a named token one shade up.

Items 1 to 3 are one feature. Items 4 to 7 are independent of it and of each other.

## Schema

One migration adds seven columns to `public.game`.

```sql
featured_at      timestamptz
featured_by      uuid references auth.users (id) on delete set null
download_kind    text check (download_kind in ('rapid','url','github'))
download_value   text check (length(btrim(download_value)) between 1 and 512)
card_path        text check (length(btrim(card_path)) between 1 and 512)
card_hash        text check (length(btrim(card_hash)) between 1 and 128)
card_staged_tier text check (card_staged_tier = 'bucket')
```

plus a table check that both download columns are null or both are set. A kind naming no value would render as a download control pointing nowhere.

`featured_at` and `featured_by` copy the `hidden_at` and `hidden_by` pair from `20260821140000_game_visibility.sql`, including `on delete set null`: closing an account must not take catalog rows with it.

The three `card_*` columns copy the shape and the length bounds of the existing `logo_*` and `banner_*` columns. `card_staged_tier` is checked against `'bucket'` alone, matching what `20260917130000_remove_vercel_blob.sql` narrowed the other two to. A new column never had a Vercel Blob era to carry.

The download kind lives in a check constraint because the set is closed at three. Per-kind format is validated in TypeScript rather than in SQL, because a format rule wants a sentence a person can act on and a constraint violation is not one.

### The view

`public.game_browse` is replaced to append, in this order:

```
featured_at, download_kind, download_value, card_path, card_hash, card_staged_tier
```

Appended rather than inserted among the existing columns, for the reason `20260914210000_game_browse_logo_staging.sql` records: `create or replace view` refuses a column added between existing ones. Grants are repeated after the replace, because `create or replace` does not carry them forward.

### Access

No new policy. Every new column is read through the existing `game_read_visible` policy, which already governs the row. Nothing here discloses anything a reader of `/games` could not already see.

Every write goes through the service-role client after an app-level permission check, which is the pattern `setGameVisibility` in `app/games/actions.ts` already follows. Featuring checks `is_moderator` directly rather than calling `editableGame`, because `editableGame` also answers true for a game's owner, and an owner must not be able to feature their own game.

A `supabase/tests/game_featured.test.sql` proves that, against real roles, the way `game_visibility.test.sql` does for hiding.

## Ordering and the featured block

`compareGames` in `lib/games/query.ts` becomes:

1. A game with `featured_at` set sorts before one without.
2. Then `localeCompare` on the displayed title, which is `display_name` falling back to `shortname`.
3. Then `shortname`, as a stable tiebreak so the order never shuffles between requests.

Sorting on the displayed title rather than the shortname because the title is the string a reader scans. `unit_count` stays in the view and on the card. It stops deciding order.

`app/games/page.tsx` renders two lists rather than one list with a spacer item:

- A featured `<ul>`, preceded by a visually hidden `<h2>`.
- A `border-t border-neutral-900` divider.
- The remaining games in their own `<ul>`, with its own hidden heading.

Two lists rather than a decorative `<li>` inside one grid, for two reasons. A presentational list item inside a list of games is a lie to a screen reader. And `sm:auto-rows-fr` then applies per block, so a tall featured card no longer sets the row height of everything below it.

When no game is featured, neither the divider nor the first list renders, and the page is what it is today.

### The toggle

A `setGameFeatured` server action in `app/games/actions.ts`, driven by the existing `VisibilityToggleForm`, placed on `/moderation/games` beside the hide controls. Featuring writes `featured_at` and `featured_by` together. Unfeaturing clears both, so a visible row never carries a stale name.

The action revalidates `/games` and `/moderation/games`.

## Download source

### Editing

A `<select>` of the three kinds and a text input, inside `GameDetailsForm` on the game edit page, saved by the action that already saves the game's words. An owner or a moderator may set it, which is what `editableGame` already answers.

### Validation

A new `lib/games/download.ts` holds one validator per kind and the message each refusal shows:

- `rapid`: a tag of the form `game:branch`, the shape coilbox's own `catalog.json` carries in `{"kind": "rapid", "tag": "metalfactions:stable"}`.
- `url`: must parse, and must be `http:` or `https:`.
- `github`: `owner/repo`, the shape `catalog.json` carries in its `githubGameRepos` entries.

### Display

On the game page, one labelled control:

- `url`: an anchor to the value.
- `github`: an anchor to `https://github.com/<owner>/<repo>/releases`.
- `rapid`: the tag in a monospace chip. A rapid tag has no URL. It is a string a lobby consumes, not something a person clicks, and dressing it as a link would lie about what it does.

### The API route

`GET /api/v1/games`, no token, CORS through the existing `corsPreflight` and `withCors` helpers. It calls the same `fetchGames` the listing page calls, so the page and the API cannot drift.

```
{ format: "coilbox-hub-games",
  version: 1,
  games: [{ shortname, title, description, featured,
            download: { kind, value } | null,
            logo, banner, card,
            faction_count, unit_count, item_count }] }
```

`logo`, `banner` and `card` are resolved URLs or null, through `gameArtUrl`, so a caller never has to know about tiers or staging.

The format string is `coilbox-hub-games`, the same one the facts and branding routes carry. All three routes talk to the same client about the same games, and a second format negotiation buys nothing.

No token, because every field describes a page the hub already serves in public.

A failed read is a 503, not an empty list. An empty list is a claim - the hub holds no games - and a client that acted on a claim the hub could not make would stop asking. The same reasoning `/api/v1/maps/lookup` records for its own 503.

## Card artwork

### The kind list gets one home first

`lib/api/gameBranding.ts` currently exports `type GameImageKind = "logo" | "banner"`, and six files repeat that pair as literals: `lib/games/art.ts`, `lib/games/imageResize.ts`, `lib/games/imageUpload.ts`, `app/games/actions.ts`, `lib/assets/promoteGameImages.ts` and `lib/games/page.ts`.

Before adding a third kind, that becomes:

```ts
export const GAME_IMAGE_KINDS = ["logo", "banner", "card"] as const;
export type GameImageKind = (typeof GAME_IMAGE_KINDS)[number];
```

and the literal pairs become reads of that list. Two spots need real changes rather than a substitution:

- `sendGameImage` in `imageUpload.ts` parses the kind as a two-way ternary (`form.get("kind") === "logo" ? "logo" : "banner"`), which silently reads `card` as `banner`. It becomes a lookup against the list.
- `uploadGameImage` in `actions.ts` maps kind to column with three ternaries. It becomes the `` `${kind}_path` `` template form `removeGameImage` in the same file already uses.

This is in scope, not speculative. Without it a fourth kind repeats the same six-file sweep, and the `imageUpload.ts` ternary is a live bug the moment a third kind exists.

### The box

`IMAGE_TARGETS.card = { maxWidth: 1280, maxHeight: 720 }`, encoded as WebP.

The hub itself never draws it larger than about 315 CSS px wide: `max-w-5xl` is 1024px, less `px-6` on both sides and two `gap-4` gutters, over three columns. Doubled for a 2x screen that is 630, so 640x360 would cover the hub alone.

It is sized for the API instead. Coilbox draws its own game cards from 16:9 loading-screen art at sizes larger than the hub will, and this column is published to it. 1280x720 is a chosen ceiling for that, not a measured requirement.

WebP rather than PNG because only the logo needs PNG, and only because the link preview renderer cannot decode WebP. Card art never reaches that renderer.

### Where it draws

A 16:9 header on the listing card, above the existing logo-and-name row. A game with no card art gets today's layout unchanged, so the whole change degrades to what is already there.

Not on the game page, which already has the banner, and not in link previews.

## The two button fixes

On `/games/[shortname]`, the edit link becomes a button-styled `Link` sharing the hide button's classes, and the two sit in one `flex items-center gap-3` row.

The hide button spans the column because `VisibilityToggleForm` defaults `formClassName` to `flex flex-col gap-1.5`, and a flex column stretches its children to the container's width. The fix passes `items-start` at that one call site rather than changing the default, because the edit page and the moderation queue already constrain the form themselves and their layout should not move.

## Card surface

`app/globals.css` gains `--card: #131317` beside the existing `--background` and `--foreground`, and `--color-card: var(--card)` inside `@theme inline`, which makes `bg-card` a utility.

All 46 sites using `bg-neutral-950`, across 27 files, swap to `bg-card`. 42 of them pair it with `border-neutral-800`, and the other 4 pair it with `border-neutral-900` or `border-red-950`. Every one is a card surface, so all 46 move together.

That includes the six per-file `const CARD` and `const ROW` strings in `app/ops/page.tsx`, `app/games/[shortname]/page.tsx`, `app/moderation/maps/page.tsx`, `app/moderation/trail/page.tsx`, `app/moderation/authors/page.tsx` and `app/moderation/users/page.tsx`. It also includes `ring-neutral-950` in `components/GameCard.tsx`, which exists to match the card behind it and has to follow it.

The 27 `bg-black` sites stay black. Those are image frames and code chips, where black is the point.

`#131317` is a chosen value, not a measured one. It is checked by screenshot before the branch becomes a pull request, not after.

## Testing

`bun test` covers:

- `compareGames`: featured first, then title, then shortname.
- The three download validators and their refusals.
- The kind list refactor, across `art.ts`, `imageUpload.ts` and `promoteGameImages.ts`.
- The `/api/v1/games` body, including a game with no download and a game with no art.

`supabase/tests/game_featured.test.sql` covers the write rules: a moderator may set `featured_at`, an owner may not, and anon may read it.

Screenshots cover the card surface, the featured divider and the two buttons. Tests cannot see a colour.

## Order of work

Each is a commit.

1. Card surface token and the class sweep.
2. The two button fixes on the game page.
3. The migration: seven columns and the view.
4. Listing sort, featured block, divider, and the moderator toggle.
5. Card art: the kind list refactor, then the third kind through to the listing card.
6. Download: validators, edit form, game page display.
7. `GET /api/v1/games`.

Screenshots before the pull request is opened.

## What this does not do

- No pagination or filters on `/games`. The catalog is still one comfortable page, which is the reason `lib/games/query.ts` gives for having neither.
- No manual ordering within the featured block. Featured games sort alphabetically among themselves, so nobody has to renumber a rank when a game is added or dropped.
- No card art on the game page or in link previews.
- No moderation queue for card art. It follows the logo and banner, which only an owner or a moderator can upload and which therefore have no queue.
