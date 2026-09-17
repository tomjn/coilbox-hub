# Games listing featured block, download sources and card art: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Order the games listing alphabetically with a moderator-pinned featured block at the top, give each game a download source and 16:9 card art, fix two buttons on the game page, and move every card surface onto a named colour token.

**Architecture:** Seven columns on `public.game` behind one migration, surfaced through the existing `public.game_browse` view so the listing page, the moderation page and a new public API route all read one shape. Writes follow the split the catalog already uses: an owner's words go through row level security with a column grant, and anything only a moderator may set goes through the service-role client after an app-level `is_moderator` check. The card colour becomes a Tailwind v4 theme token in `app/globals.css`, which is the only place the app defines colour.

**Tech Stack:** Next.js (canary, see `AGENTS.md`), React server components, Tailwind CSS v4 with CSS-first config, Supabase Postgres with row level security, `bun test` for unit tests, pgTAP via `supabase test db` for policy tests.

**Spec:** `docs/superpowers/specs/2026-09-17-games-listing-featured-download-design.md`

## Global constraints

- Read `node_modules/next/dist/docs/` before writing Next.js code. This is not the Next.js in your training data. `AGENTS.md` says so at the repo root.
- Never hard-wrap markdown, commit messages or comments at a fixed column. Soft wrap only.
- No emoji anywhere. No em dashes, use plain hyphens.
- Comments in this codebase explain why, at length, in full sentences. Match that. Do not add a comment that restates the line below it.
- Every new colour goes in `app/globals.css`. Nowhere else defines one.
- Tests run with `bun test`. Database tests run with `supabase test db` and live in `supabase/tests/*.test.sql`.
- The full check set is `bun run lint`, `bun run typecheck`, `bun test`, `bun run build`. CI runs all four plus `supabase test db`.
- Commit after each task. Do not amend. Do not force push.
- Do not run `git add -A`. Add files explicitly.
- Branch is `games-listing-featured-download`, already created, spec already committed on it.

---

### Task 1: Card surface colour token

**Files:**
- Modify: `app/globals.css:5-16`
- Modify: 27 files under `app/` and `components/` containing `bg-neutral-950`
- Modify: `components/GameCard.tsx:100`

**Interfaces:**
- Consumes: nothing.
- Produces: the `bg-card` and `ring-card` Tailwind utilities, backed by `--color-card`. Later tasks that add card surfaces use `bg-card`, never `bg-neutral-950`.

There is no unit test in this task. It changes only class names and one CSS custom property, and the repo has no test that asserts on class strings. It is verified by `bun run build` and by screenshot. That is stated here rather than faked with a test that proves nothing.

- [ ] **Step 1: Add the token to globals.css**

In `app/globals.css`, add `--card` to `:root` and `--color-card` to `@theme inline`:

```css
:root {
  color-scheme: dark;
  --background: #0b0b0d;
  --foreground: #fafafa;
  /* One step up from the page, so a card reads as something sitting on the
     background rather than a hole cut in it. Every card surface in the app
     points here, which is why it is a token and not a utility class repeated
     46 times. */
  --card: #131317;
}

@theme inline {
  --color-background: var(--background);
  --color-foreground: var(--foreground);
  --color-card: var(--card);
  --font-sans: var(--font-geist-sans);
  --font-mono: var(--font-geist-mono);
}
```

- [ ] **Step 2: Confirm the count before the sweep**

Run:

```bash
grep -rn "bg-neutral-950" app components | wc -l
grep -rln "bg-neutral-950" app components | wc -l
```

Expected: 46 and 27. If either number differs, the tree has moved since this plan was written. Stop and report the new numbers rather than guessing which sites are cards.

- [ ] **Step 3: Sweep the class**

Run:

```bash
grep -rl "bg-neutral-950" app components | xargs sed -i '' 's/bg-neutral-950/bg-card/g'
```

All 46 sites are card surfaces. 42 pair the class with `border-neutral-800`, and the other 4 pair it with `border-neutral-900` or `border-red-950`. Nothing uses it for a non-card purpose, so the sweep is unconditional.

Leave every `bg-black` alone. Those 27 sites are image frames and code chips, where black is the point.

- [ ] **Step 4: Fix the matching ring**

In `components/GameCard.tsx:100`, the commander avatars are ringed in the card's own colour so the overlap reads as a line up. That ring has to follow the card:

```tsx
className="size-9 bg-black ring-2 ring-card"
```

- [ ] **Step 5: Verify nothing is left behind and the build passes**

Run:

```bash
grep -rn "bg-neutral-950\|ring-neutral-950" app components
bun run lint && bun run typecheck && bun run build
```

Expected: the grep prints nothing, and all three commands exit 0.

- [ ] **Step 6: Commit**

```bash
git add app/globals.css app components
git commit -m "Give card surfaces a colour of their own

The near-black card sat so close to the page background that a card read as a
hole rather than a surface. It is a token rather than a utility because it was
written out 46 times, and the next change to it should be one line."
```

---

### Task 2: The two buttons on a game page

**Files:**
- Modify: `app/games/[shortname]/page.tsx:217-238`

**Interfaces:**
- Consumes: `VisibilityToggleForm` from `@/components/VisibilityToggleForm`, whose `formClassName` prop already exists and defaults to `"flex flex-col gap-1.5"`.
- Produces: `OWNER_CONTROL`, a class string in the same file, which Task 6 also uses.

No unit test, for the same reason as Task 1. Verified by build and screenshot.

- [ ] **Step 1: Replace both blocks with one row**

The edit link is a sentence with a full stop after it, and the hide button spans the whole column because `VisibilityToggleForm` defaults to a flex column, which stretches its children. Replace lines 217-238 with:

```tsx
          {mayEdit ? (
            <div className="flex flex-wrap items-center gap-3 pt-1">
              <Link href={`/games/${shortname}/edit`} className={OWNER_CONTROL}>
                Edit this game
              </Link>
              <VisibilityToggleForm
                action={setGameVisibility}
                fields={{ shortname, hidden: "true", onSuccess: "edit" }}
                label="Hide this game"
                pendingLabel="Hiding…"
                // The default is a flex column, which stretches its button
                // across the page's whole width. Only this call site needs
                // fixing: the edit page and the moderation queue pass their
                // own layout already.
                formClassName="flex flex-col items-start gap-1.5"
                buttonClassName={OWNER_CONTROL}
              />
            </div>
          ) : null}
```

- [ ] **Step 2: Add the shared control class**

Both controls now look the same, so the string lives once. Add it beside the existing `const CARD` near the top of the same file, around line 69:

```tsx
/** The owner's two controls on this page. A link and a button that do
 *  neighbouring jobs should not look like two different kinds of thing. */
const OWNER_CONTROL =
  "rounded-md border border-neutral-800 px-3 py-1.5 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white disabled:opacity-60";
```

- [ ] **Step 3: Verify**

Run: `bun run lint && bun run typecheck && bun run build`
Expected: exit 0 from all three.

- [ ] **Step 4: Commit**

```bash
git add "app/games/[shortname]/page.tsx"
git commit -m "Pair a game's owner controls as two buttons

Edit was a sentence and hide was a button the width of the page, which read as
two unrelated things rather than the two things an owner does here."
```

---

### Task 3: The migration

**Files:**
- Create: `supabase/migrations/20260918120000_game_featured_download_card.sql`
- Create: `supabase/tests/game_featured.test.sql`

**Interfaces:**
- Produces: on `public.game`, the columns `featured_at`, `featured_by`, `download_kind`, `download_value`, `card_path`, `card_hash`, `card_staged_tier`. On `public.game_browse`, six of them appended after `logo_staged_tier`. An `authenticated` update grant widened to include `download_kind` and `download_value`.

- [ ] **Step 1: Write the failing database test**

Create `supabase/tests/game_featured.test.sql`. It proves the write rules that matter: a moderator may feature a game, an owner may not, everybody can read the column, and the download pair moves together.

```sql
-- Who may put a game at the top of the listing, run as the roles PostgREST
-- actually uses.
--
-- Featuring is the first game column that an owner may read and only a
-- moderator may write. Hiding is close but not the same: an owner may hide
-- their own game, because hiding is a decision about their own page, while
-- featuring is a decision about the hub's front door. This proves the
-- difference holds at the data layer rather than in whichever page remembered
-- to check.

begin;
select plan(6);

create extension if not exists pgtap with schema extensions;

insert into auth.users (id, instance_id, aud, role, email)
values
  ('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'owner@example.test'),
  ('cccccccc-cccc-cccc-cccc-cccccccccccc', '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'moderator@example.test');

insert into public.user_capability (user_id, capability)
values ('cccccccc-cccc-cccc-cccc-cccccccccccc', 'can_moderate');

insert into public.game (id, shortname, owner_user_id)
values ('0f8fad5b-0007-4000-8000-000000000042', 'BA', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');

-- service_role features it, which is what the server action does once it has
-- checked who is asking.
reset role;
set local role service_role;

update public.game
set featured_at = now(), featured_by = 'cccccccc-cccc-cccc-cccc-cccccccccccc'
where shortname = 'BA';

select is(
  (select featured_at is not null from public.game where shortname = 'BA'),
  true,
  'service_role can feature a game'
);

-- Both download columns move together or not at all.
select throws_ok(
  $$update public.game set download_kind = 'url', download_value = null where shortname = 'BA'$$,
  '23514',
  null,
  'a download kind with no value is refused'
);

-- anon reads it, because the listing has to draw the featured block for a
-- signed out visitor.
reset role;
set local role anon;
set local request.jwt.claims = '{"role":"anon"}';

select is(
  (select featured_at is not null from public.game_browse where shortname = 'BA'),
  true,
  'anon reads featured_at through the browse view'
);

select is(
  (select count(*)::integer from public.game_browse where shortname = 'BA'),
  1,
  'featuring does not change what anon can see'
);

-- The owner may not write it. The column grant is what refuses this, before
-- any policy is consulted.
reset role;
set local role authenticated;
set local request.jwt.claims = '{"sub":"aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa","role":"authenticated"}';

select throws_ok(
  $$update public.game set featured_at = null where shortname = 'BA'$$,
  '42501',
  null,
  'the owner may not unfeature their own game'
);

-- The owner may still write the columns they have always written, and the
-- download source now among them.
select lives_ok(
  $$update public.game set display_name = 'Balanced Annihilation', download_kind = 'rapid', download_value = 'ba:stable' where shortname = 'BA'$$,
  'the owner may set the display name and the download source'
);

select * from finish();
rollback;
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
supabase db start
supabase test db
```

Expected: FAIL. `game_featured.test.sql` errors on `column "featured_at" does not exist`.

- [ ] **Step 3: Write the migration**

Create `supabase/migrations/20260918120000_game_featured_download_card.sql`:

```sql
-- Three things a game row could not say yet: that the hub wants it seen first,
-- where a lobby fetches it from, and what it looks like on a card.
--
-- ## Featuring is not hiding upside down
--
-- Hiding is a decision an owner may make about their own page, so
-- 20260821140000_game_visibility.sql lets an owner hide their own game.
-- Featuring is a decision about the hub's front door, so it stays behind
-- service_role and the server action checks is_moderator() before spending it.
-- The column pair and the on delete set null are copied from hidden_at and
-- hidden_by for the same reason those exist: who and when are both part of the
-- fact, and closing an account must not take the catalog row with it.
--
-- ## Why the download source is typed columns
--
-- public.game.links is a jsonb blob, and its comment gives the test it passed:
-- the set of places worth linking is open ended and nothing filters on it. A
-- download source fails that test. There are three kinds, the set is closed,
-- and a client reads the kind to decide what to do with the value. So the kind
-- is a column with a check constraint on it.
--
-- The per-kind format - what a rapid tag looks like, what a github value looks
-- like - is validated in lib/games/download.ts rather than here. A constraint
-- violation is not a sentence anybody can act on, and these are strings a
-- person types into a form. The constraint still holds the kind and the
-- length, so a write that skipped that file cannot store nonsense.
--
-- ## Card art is a third picture, not a new mechanism
--
-- The three card_ columns match logo_ and banner_ exactly, so every reader,
-- the upload action, the promotion run and the hub's own art route treat it as
-- the same kind of thing. card_staged_tier is checked against 'bucket' alone,
-- which is what 20260917130000_remove_vercel_blob.sql narrowed the other two
-- to. A column created today never had a Vercel Blob era to carry.

alter table public.game
  add column featured_at timestamptz,
  add column featured_by uuid references auth.users (id) on delete set null,

  add column download_kind text
    check (download_kind in ('rapid', 'url', 'github')),
  add column download_value text
    check (length(btrim(download_value)) between 1 and 512),

  add column card_path text check (length(btrim(card_path)) between 1 and 512),
  add column card_hash text check (length(btrim(card_hash)) between 1 and 128),
  add column card_staged_tier text check (card_staged_tier = 'bucket');

-- A kind naming no value would draw a download control pointing nowhere, and a
-- value with no kind is a string nothing knows how to use. Neither half means
-- anything alone.
alter table public.game
  add constraint game_download_pair_check
  check ((download_kind is null) = (download_value is null));

comment on column public.game.featured_at is
  'When a moderator put this game at the top of the listing, or null. Only service_role writes it.';
comment on column public.game.download_kind is
  'How a lobby fetches this game: a rapid tag, a URL, or a GitHub repo with releases. Null when nobody has said.';
comment on column public.game.download_value is
  'The tag, URL or owner/repo the kind names. Format is checked in lib/games/download.ts.';
comment on column public.game.card_staged_tier is
  'Set to bucket while an uploaded card picture waits for promotion to the durable tier, and cleared once it is there.';

-- ## The owner's pen widens by two columns
--
-- 20260821130000_game_ownership.sql grants an owner update on the display
-- name, the description and the links, and says everything else on the row is
-- a decision about the catalog rather than words about the game. Where a game
-- is downloaded from is the game's own fact, told by the people who ship it,
-- so it joins the three. featured_at deliberately does not: that is the hub
-- talking about the game, not the game talking about itself.
revoke update on public.game from authenticated;
grant update (display_name, description, links, download_kind, download_value)
  on public.game to authenticated;

-- ## The view
--
-- Six columns appended rather than inserted among the existing ones, for the
-- reason 20260914210000_game_browse_logo_staging.sql records: create or
-- replace view refuses a column added between existing ones. Nothing here
-- discloses anything new, since anon can already select every column of
-- public.game and security_invoker keeps the game's read policy in force.
create or replace view public.game_browse
with (security_invoker = true) as
select
  g.shortname,
  g.display_name,
  g.description,

  -- How many sides the game has, as the archive names them.
  (select count(*) from public.game_faction as gf where gf.game_id = g.id)::integer
    as faction_count,

  -- How many playable units, retired ones excluded.
  (select count(*) from public.game_unit as gu
    where gu.game_id = g.id and gu.removed_at is null)::integer
    as unit_count,

  -- How much community content is published for it (#244).
  (select count(*) from public.item as i
    where i.game_key = g.shortname and i.deleted_at is null)::integer
    as item_count,

  -- Tier relative path to the game's logo, or null when none is held (#239).
  g.logo_path,

  -- The hash of the logo's bytes, and which store holds a copy still waiting
  -- for promotion (#345).
  g.logo_hash,
  g.logo_staged_tier,

  -- Whether the listing draws this one above the divider.
  g.featured_at,

  -- Where a lobby fetches the game from.
  g.download_kind,
  g.download_value,

  -- The card picture, resolved by the same three columns every other game
  -- picture uses.
  g.card_path,
  g.card_hash,
  g.card_staged_tier
from public.game as g;

-- Repeated from the view's first migration, because create or replace does not
-- carry grants forward.
revoke all on public.game_browse from anon, authenticated, service_role;
grant select on public.game_browse to anon, authenticated, service_role;
```

- [ ] **Step 4: Run the test to verify it passes**

Run:

```bash
supabase db reset
supabase test db
```

Expected: PASS, including the 6 assertions in `game_featured.test.sql` and every pre-existing test file.

- [ ] **Step 5: Commit**

```bash
git add supabase/migrations/20260918120000_game_featured_download_card.sql supabase/tests/game_featured.test.sql
git commit -m "Let a game row say it is featured, where to get it, and what its card looks like

Featuring stays behind service_role while hiding does not, because hiding is a
decision about an owner's own page and featuring is a decision about the hub's
front door. The download source is typed columns rather than joining the links
blob: the set of kinds is closed at three and a client reads the kind to decide
what to do with the value."
```

---

### Task 4: Alphabetical order, the featured block and the moderator toggle

**Files:**
- Modify: `lib/games/query.ts:12-56`
- Create: `lib/games/query.test.ts`
- Modify: `app/games/page.tsx:43-76`
- Modify: `app/games/actions.ts` (add `setGameFeatured` after `setGameVisibility`, around line 308)
- Modify: `lib/games/formState.ts` (add `FEATURED_MESSAGES`)
- Modify: `app/moderation/games/page.tsx` (add a Featured section after the Visibility section)

**Interfaces:**
- Consumes: `featured_at` from Task 3's view. `gameTitle` from `@/lib/games/labels`, signature `gameTitle(game: Pick<GameSummary, "shortname" | "display_name">): string`.
- Produces: `GameSummary.featured_at: string | null` plus the download and card fields. `compareGames` exported from `lib/games/query.ts` for its test. `setGameFeatured(previous: GameFormState | null, form: FormData): Promise<GameFormState>` in `app/games/actions.ts`, taking `shortname` and `featured` (`"true"` or `"false"`) form fields.

- [ ] **Step 1: Write the failing sort test**

Create `lib/games/query.test.ts`:

```ts
import { expect, test } from "bun:test";
import { compareGames, type GameSummary } from "./query";

/** A row with only the fields the sort reads. The rest of GameSummary is
 *  filled in so the type holds without each test restating it. */
function game(fields: Partial<GameSummary> & Pick<GameSummary, "shortname">): GameSummary {
  return {
    display_name: null,
    description: null,
    logo_path: null,
    logo_hash: null,
    logo_staged_tier: null,
    card_path: null,
    card_hash: null,
    card_staged_tier: null,
    featured_at: null,
    download_kind: null,
    download_value: null,
    faction_count: 0,
    unit_count: 0,
    item_count: 0,
    ...fields,
  };
}

test("games sort by the name a reader sees, not by the shortname", () => {
  const sorted = [
    game({ shortname: "ZK", display_name: "Zero-K" }),
    game({ shortname: "BAR", display_name: "Beyond All Reason" }),
    game({ shortname: "AA", display_name: "Metal Factions" }),
  ]
    .sort(compareGames)
    .map((row) => row.shortname);

  // AA sorts in the middle despite its shortname, because Metal Factions is
  // what the card says.
  expect(sorted).toEqual(["BAR", "AA", "ZK"]);
});

test("a game with no display name sorts on its shortname", () => {
  const sorted = [game({ shortname: "Zed" }), game({ shortname: "Alpha" })]
    .sort(compareGames)
    .map((row) => row.shortname);

  expect(sorted).toEqual(["Alpha", "Zed"]);
});

test("featured games come first, alphabetical among themselves", () => {
  const sorted = [
    game({ shortname: "AAA" }),
    game({ shortname: "ZZZ", featured_at: "2026-09-18T00:00:00Z" }),
    game({ shortname: "MMM", featured_at: "2026-09-17T00:00:00Z" }),
  ]
    .sort(compareGames)
    .map((row) => row.shortname);

  // The older feature does not win. Inside the block it is alphabetical, so
  // nobody has to keep a rank in order.
  expect(sorted).toEqual(["MMM", "ZZZ", "AAA"]);
});

test("unit count no longer decides the order", () => {
  const sorted = [
    game({ shortname: "small", unit_count: 2 }),
    game({ shortname: "huge", unit_count: 900 }),
  ]
    .sort(compareGames)
    .map((row) => row.shortname);

  expect(sorted).toEqual(["huge", "small"]);
});

test("two games with the same title are told apart by shortname", () => {
  const sorted = [
    game({ shortname: "BB", display_name: "Same" }),
    game({ shortname: "AA", display_name: "Same" }),
  ]
    .sort(compareGames)
    .map((row) => row.shortname);

  expect(sorted).toEqual(["AA", "BB"]);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `bun test lib/games/query.test.ts`
Expected: FAIL, `compareGames` is not exported and `featured_at` is not a property of `GameSummary`.

- [ ] **Step 3: Rewrite the summary type, the columns and the sort**

In `lib/games/query.ts`, add the new fields to `GameSummary` after `logo_staged_tier`:

```ts
  /** When a moderator put this game above the divider, or null. The listing
   *  reads it as a flag. The time itself is kept because who and when are part
   *  of the fact, the same way hidden_at is. */
  featured_at: string | null;
  /** Where a lobby fetches the game from, or null when nobody has said. Both
   *  columns are set or neither is, which the table's own check enforces. */
  download_kind: string | null;
  download_value: string | null;
  /** The 16:9 picture the card draws above the name, resolved the same way the
   *  logo is. */
  card_path: string | null;
  card_hash: string | null;
  card_staged_tier: string | null;
```

Extend the column list:

```ts
export const GAME_SUMMARY_COLUMNS =
  "shortname,display_name,description,logo_path,logo_hash,logo_staged_tier," +
  "featured_at,download_kind,download_value,card_path,card_hash,card_staged_tier," +
  "faction_count,unit_count,item_count";
```

Replace `compareGames` and export it:

```ts
/**
 * Featured first, then alphabetical.
 *
 * This used to be biggest first, on the theory that a visitor arriving cold
 * wants the game with the most units to read about. Size turned out to be a
 * poor stand-in for that, and it left the order at the mercy of whichever
 * extraction ran last. A moderator now says what goes first, and everything
 * else is in the order a person would look for it in.
 *
 * The comparison is on the title a reader sees rather than the shortname,
 * because the title is the string they are scanning. `localeCompare` so that
 * an accented name lands where a reader expects rather than after Z.
 * Shortname is the final tiebreak, and it is unique, so the order never
 * shuffles between requests.
 */
export function compareGames(left: GameSummary, right: GameSummary): number {
  const leftFeatured = left.featured_at !== null;
  const rightFeatured = right.featured_at !== null;
  if (leftFeatured !== rightFeatured) return leftFeatured ? -1 : 1;

  const byTitle = gameTitle(left).localeCompare(gameTitle(right));
  if (byTitle !== 0) return byTitle;

  return left.shortname.localeCompare(right.shortname);
}
```

Add the import at the top of the file:

```ts
import { gameTitle } from "./labels";
```

Check for an import cycle before moving on. `lib/games/labels.ts` imports `GameSummary` from `./query` as a type only, and types erase, so this is safe. If `bun run typecheck` disagrees, inline the two-line body of `gameTitle` here rather than restructuring either file.

- [ ] **Step 4: Run the test to verify it passes**

Run: `bun test lib/games/query.test.ts`
Expected: PASS, 5 tests.

- [ ] **Step 5: Split the listing into two blocks**

In `app/games/page.tsx`, add above the page component:

```tsx
/** One block of cards. Two of these rather than one list with a divider
 *  inside it: a presentational item in a list of games is a lie to a screen
 *  reader, and a list per block means a tall featured card no longer sets the
 *  row height of everything under it. */
function GameGrid({
  heading,
  rows,
  sides,
}: {
  heading: string;
  rows: GameSummary[];
  sides: Map<string, GameSides>;
}) {
  return (
    <section className="flex flex-col gap-4">
      <h2 className="sr-only">{heading}</h2>
      <ul className="grid gap-4 sm:auto-rows-fr sm:grid-cols-2 lg:grid-cols-3">
        {rows.map((game) => (
          <GameCard key={game.shortname} game={game} sides={sides.get(game.shortname)} />
        ))}
      </ul>
    </section>
  );
}
```

In the component body, after the `gameSidesCached` call:

```tsx
  // Already sorted featured-first by `compareGames`, so this is a split rather
  // than a second sort.
  const featured = rows.filter((game) => game.featured_at !== null);
  const rest = rows.slice(featured.length);
```

Replace the list branch of the render (the current single `<ul>`, lines 71-75) with:

```tsx
          <div className="flex flex-col gap-6">
            {featured.length > 0 ? (
              <GameGrid heading="Featured games" rows={featured} sides={sides} />
            ) : null}
            {featured.length > 0 && rest.length > 0 ? (
              <hr className="border-neutral-900" />
            ) : null}
            {rest.length > 0 ? (
              <GameGrid heading="All games" rows={rest} sides={sides} />
            ) : null}
          </div>
```

Add the two type imports the new component needs:

```tsx
import type { GameSummary } from "@/lib/games/query";
import type { GameSides } from "@/lib/games/sides";
```

- [ ] **Step 6: Add the featured messages**

In `lib/games/formState.ts`, after `VISIBILITY_MESSAGES`:

```ts
/** What the feature control says. Separate from VISIBILITY_MESSAGES because
 *  the refusal is a different one: an owner may hide their own game and may
 *  not feature it, so "only the owner or a moderator" would be wrong here. */
export const FEATURED_MESSAGES = {
  featured: "Game featured.",
  unfeatured: "Game no longer featured.",
  signedOut: "You are signed out. Sign in, then try again.",
  notAllowed: "Only a moderator can feature a game.",
  notFound: "No game with that shortname.",
  notSaved: "That could not be saved. Try again in a few minutes.",
  notSent: "The form did not reach the hub. Reload the page and try again.",
} as const;
```

- [ ] **Step 7: Add the server action**

In `app/games/actions.ts`, after `setGameVisibility`:

```ts
/**
 * Put a game at the top of the listing, or take it back down.
 *
 * Unlike `setGameVisibility` this does not call `editableGame`, and the
 * difference is the whole point: `editableGame` answers true for a game's
 * owner as well as a moderator, and an owner featuring their own game is
 * exactly what the column grant refuses. So the question asked here is
 * `is_moderator` and nothing else, with the visitor's own client, before the
 * secret key is spent.
 */
export async function setGameFeatured(
  _previous: GameFormState | null,
  form: FormData,
): Promise<GameFormState> {
  const shortname = String(form.get("shortname") ?? "").trim();
  const featured = form.get("featured") === "true";
  if (!shortname) return { ok: false, message: FEATURED_MESSAGES.notSent };

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) return { ok: false, message: FEATURED_MESSAGES.signedOut };

  const { data: moderator } = await supabase.rpc("is_moderator");
  if (moderator !== true) return { ok: false, message: FEATURED_MESSAGES.notAllowed };

  const admin = createAdmin();
  if (!admin) {
    console.error(`setGameFeatured: no secret key, so ${shortname} was not updated`);
    return { ok: false, message: FEATURED_MESSAGES.notSaved };
  }

  // Both columns ride one update, and unfeaturing clears both, so a game on
  // the ordinary side of the divider never carries a stale name.
  const { data, error } = await admin
    .from("game")
    .update(
      featured
        ? { featured_at: new Date().toISOString(), featured_by: user.id }
        : { featured_at: null, featured_by: null },
    )
    .eq("shortname", shortname)
    .select("shortname");

  if (error) {
    console.error(`setGameFeatured: ${shortname} was not updated`, error);
    return { ok: false, message: FEATURED_MESSAGES.notSaved };
  }
  if (!data || data.length === 0) {
    return { ok: false, message: FEATURED_MESSAGES.notFound };
  }

  updateTag(TAGS.games);
  revalidatePath("/games");
  revalidatePath("/moderation/games");

  return {
    ok: true,
    message: featured ? FEATURED_MESSAGES.featured : FEATURED_MESSAGES.unfeatured,
  };
}
```

Add `FEATURED_MESSAGES` to the existing import from `@/lib/games/formState`.

- [ ] **Step 8: Add the moderation control**

In `app/moderation/games/page.tsx`, extend the existing `Promise.all`:

```tsx
  const [hiddenGames, hiddenVersions, featuredGames] = await Promise.all([
    supabase.from("game").select("shortname,hidden_at").not("hidden_at", "is", null),
    supabase
      .from("game_version")
      .select("version,hidden_at,game(shortname)")
      .not("hidden_at", "is", null)
      .order("hidden_at", { ascending: false }),
    supabase
      .from("game")
      .select("shortname,featured_at")
      .not("featured_at", "is", null)
      .order("shortname"),
  ]);

  const featuredRows = (featuredGames.data ?? []) as unknown as { shortname: string }[];
```

Add a section after the Visibility section's closing tag:

```tsx
        <section className="flex flex-col gap-3 border-t border-neutral-900 pt-6" aria-labelledby="mod-featured">
          <h2 id="mod-featured" className="text-sm uppercase tracking-wide text-neutral-400">
            Featured
          </h2>
          <p className="text-sm text-neutral-500">
            A featured game sits above the divider on the games listing. Inside that block the order
            is alphabetical, so there is no rank to keep.
          </p>

          <VisibilityToggleForm
            action={setGameFeatured}
            fields={{ featured: "true" }}
            label="Feature"
            pendingLabel="Featuring…"
            formClassName="flex flex-wrap items-end gap-2"
            buttonClassName="rounded-md border border-neutral-800 px-3 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white disabled:opacity-60"
          >
            <div className="flex flex-col gap-1.5">
              <label htmlFor="feature-shortname" className="text-xs uppercase tracking-wide text-neutral-500">
                Feature a game
              </label>
              <input
                id="feature-shortname"
                name="shortname"
                placeholder="Shortname, e.g. BA"
                required
                maxLength={64}
                className="w-48 rounded-md border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-600 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400"
              />
            </div>
          </VisibilityToggleForm>

          {featuredRows.length > 0 ? (
            <ul className="flex flex-col gap-1.5 pt-2">
              {featuredRows.map((row) => (
                <li key={row.shortname} className="flex items-center justify-between gap-3 text-sm">
                  <span className="font-mono text-neutral-300">{row.shortname}</span>
                  <VisibilityToggleForm
                    action={setGameFeatured}
                    fields={{ shortname: row.shortname, featured: "false" }}
                    label="Unfeature"
                    pendingLabel="Removing…"
                    buttonClassName="rounded-md border border-neutral-800 px-3 py-1 text-xs text-neutral-400 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-neutral-200 active:text-neutral-200 disabled:opacity-60"
                  />
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-neutral-500">No game is featured right now.</p>
          )}
        </section>
```

Add `setGameFeatured` to the existing import from `@/app/games/actions`.

`VisibilityToggleForm` is named for hiding but is a generic "one field, a button, and its answer" form. Reusing it is right. Do not rename it in this task: it is used in five places and a rename would bury this change in noise.

- [ ] **Step 9: Verify the whole suite**

Run: `bun test && bun run lint && bun run typecheck && bun run build`
Expected: exit 0 from all four.

- [ ] **Step 10: Commit**

```bash
git add lib/games/query.ts lib/games/query.test.ts lib/games/formState.ts app/games/page.tsx app/games/actions.ts app/moderation/games/page.tsx
git commit -m "Sort games by name, and let a moderator pin one to the top

Biggest-first made the order a side effect of whichever extraction ran last,
and it answered a question nobody was asking. A reader scanning for a game
wants it where they would look for it, and the hub wants to be able to say
what goes first."
```

---

### Task 5: Card art as a third picture kind

**Files:**
- Modify: `lib/api/gameBranding.ts:17` and `:55-58`
- Modify: `lib/games/art.ts:47`
- Modify: `lib/games/imageResize.ts:38-41`
- Modify: `lib/games/imageUpload.ts:106`
- Modify: `app/games/actions.ts` (`uploadGameImage` around lines 369-435, `removeGameImage` around lines 454-492)
- Modify: `lib/assets/promoteGameImages.ts:67-85`, `:107-120`, `:136-165`
- Modify: `lib/games/page.ts:45-121`
- Modify: `app/games/[shortname]/edit/GameImageForm.tsx:16` and `:39`
- Modify: `app/games/[shortname]/edit/GameImageRemoveForm.tsx`
- Modify: `app/games/[shortname]/edit/page.tsx`
- Modify: `components/GameCard.tsx`
- Create: `lib/games/imageKinds.test.ts`

**Interfaces:**
- Consumes: `card_path`, `card_hash`, `card_staged_tier` from Task 3, and `GameSummary`'s card fields from Task 4.
- Produces: `GAME_IMAGE_KINDS: readonly ["logo", "banner", "card"]` exported from `lib/api/gameBranding.ts`, with `GameImageKind` derived from it, and `isGameImageKind(value: string): value is GameImageKind`.

- [ ] **Step 1: Write the failing kind-list test**

Create `lib/games/imageKinds.test.ts`:

```ts
import { expect, test } from "bun:test";
import { GAME_IMAGE_KINDS, isGameImageKind, parseGameBrandingFields } from "@/lib/api/gameBranding";
import { IMAGE_TARGETS, targetFormat } from "./imageResize";
import { sendGameImage } from "./imageUpload";

test("the three kinds are listed in one place", () => {
  expect(GAME_IMAGE_KINDS).toEqual(["logo", "banner", "card"]);
});

test("every kind has a box and a format", () => {
  for (const kind of GAME_IMAGE_KINDS) {
    expect(IMAGE_TARGETS[kind].maxWidth).toBeGreaterThan(0);
    expect(targetFormat(kind)).toMatch(/^image\/(png|webp)$/);
  }
});

test("only the logo is forced to PNG, because only it reaches the preview renderer", () => {
  expect(targetFormat("logo")).toBe("image/png");
  expect(targetFormat("banner")).toBe("image/webp");
  expect(targetFormat("card")).toBe("image/webp");
});

test("card art is 16:9", () => {
  const { maxWidth, maxHeight } = IMAGE_TARGETS.card;
  expect(maxWidth / maxHeight).toBeCloseTo(16 / 9, 5);
});

test("isGameImageKind refuses anything not in the list", () => {
  expect(isGameImageKind("card")).toBe(true);
  expect(isGameImageKind("screenshot")).toBe(false);
  expect(isGameImageKind("")).toBe(false);
});

test("the branding route accepts a card", () => {
  const form = new FormData();
  form.set("shortname", "BA");
  form.set("kind", "card");
  expect(parseGameBrandingFields(form)).toEqual({ ok: true, shortname: "BA", kind: "card" });
});

test("the branding route still refuses a kind it does not hold", () => {
  const form = new FormData();
  form.set("shortname", "BA");
  form.set("kind", "screenshot");
  expect(parseGameBrandingFields(form).ok).toBe(false);
});

test("a card upload is not sent as a banner", async () => {
  // The bug this guards: sendGameImage read the kind with a two-way ternary,
  // so anything that was not "logo" became "banner", and a card would have
  // been shrunk to the banner's 2048x448 box and stored under its name.
  const form = new FormData();
  form.set("kind", "card");
  form.set("image", new File([new Uint8Array(8)], "card.png", { type: "image/png" }));

  // An array rather than a `let`, because TypeScript narrows a variable
  // assigned only inside a callback and then rejects the comparison below.
  const seen: string[] = [];
  await sendGameImage(
    async (_previous, sent) => {
      seen.push(String(sent.get("kind")));
      return { ok: true, message: "stored" };
    },
    null,
    form,
    async (file) => ({ ok: true, file, message: "converted" }),
  );

  expect(seen).toEqual(["card"]);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `bun test lib/games/imageKinds.test.ts`
Expected: FAIL, `GAME_IMAGE_KINDS` and `isGameImageKind` are not exported.

- [ ] **Step 3: Give the kind list one home**

In `lib/api/gameBranding.ts`, replace the `GameImageKind` type:

```ts
/**
 * The kinds of picture a game row can carry, which are also the only values
 * `kind` accepts.
 *
 * A list rather than a union of string literals, because six files repeated
 * the pair as literals and adding a third meant finding all six. Every one of
 * them now reads this.
 */
export const GAME_IMAGE_KINDS = ["logo", "banner", "card"] as const;

export type GameImageKind = (typeof GAME_IMAGE_KINDS)[number];

export function isGameImageKind(value: string): value is GameImageKind {
  return (GAME_IMAGE_KINDS as readonly string[]).includes(value);
}
```

and rewrite the kind branch of `parseGameBrandingFields`:

```ts
  const kind = String(form.get("kind") ?? "");
  if (!isGameImageKind(kind)) {
    return { ok: false, error: `The "kind" part must be one of ${GAME_IMAGE_KINDS.join(", ")}.` };
  }
```

- [ ] **Step 4: Widen the art kind and the boxes**

In `lib/games/art.ts:47`, drop the separate union and re-export the shared one:

```ts
export type { GameImageKind as GameArtKind } from "@/lib/api/gameBranding";
```

Change every use of `GameArtKind` in that file to the imported type, and update the file's doc comment, which says "a game's logo or banner", to say "a game's pictures".

In `lib/games/imageResize.ts`, add the card box:

```ts
export const IMAGE_TARGETS: Record<GameImageKind, { maxWidth: number; maxHeight: number }> = {
  logo: { maxWidth: 128, maxHeight: 128 },
  banner: { maxWidth: 2048, maxHeight: 448 },
  card: { maxWidth: 1280, maxHeight: 720 },
};
```

and extend that file's "Target dimensions" comment with:

```
 * - Card: 16:9 art for the listing card. The hub itself never draws it wider
 *   than about 315 CSS px - `max-w-5xl` is 1024px, less `px-6` each side and
 *   two `gap-4` gutters, over three columns - so 640x360 would cover this site
 *   alone. It is sized for `/api/v1/games` instead, which publishes it to
 *   coilbox, whose own game cards draw 16:9 loading-screen art larger than
 *   anything here. 1280x720 is a chosen ceiling for that, not a measured one.
```

`targetFormat` needs no change. It returns PNG for `logo` and WebP for everything else, which is already right for a card.

- [ ] **Step 5: Fix the two-way kind parse**

In `lib/games/imageUpload.ts:106`, replace:

```ts
  const kind = (form.get("kind") === "logo" ? "logo" : "banner") as GameImageKind;
```

with:

```ts
  // A lookup rather than a ternary. The old two-way form read every kind that
  // was not "logo" as "banner", which would have shrunk a card to the banner's
  // box and stored it under the banner's name.
  const raw = String(form.get("kind") ?? "");
  if (!isGameImageKind(raw)) return refused(UPLOAD_MESSAGES.noKind);
  const kind = raw;
```

Add `isGameImageKind` to the import from `@/lib/api/gameBranding`, and add the message to `UPLOAD_MESSAGES`:

```ts
  noKind: "The upload did not say which picture it is. Reload the page and try again.",
```

- [ ] **Step 6: Make the action kind-agnostic**

In `app/games/actions.ts`, near the top add:

```ts
/** What each picture is called in the sentence that confirms it saved. */
const IMAGE_LABELS: Record<GameImageKind, string> = {
  logo: "Logo",
  banner: "Banner",
  card: "Card art",
};
```

In `uploadGameImage`, replace the guard:

```ts
  const kind = String(form.get("kind") ?? "");
  if (!shortname || !isGameImageKind(kind)) {
    return { ok: false, message: UPLOAD_MESSAGES.notSent };
  }
```

replace the three column ternaries with the template form `removeGameImage` already uses:

```ts
  const hash = await encodedHash(bytes.buffer as ArrayBuffer);
  const { error } = await admin
    .from("game")
    .update({
      [`${kind}_path`]: path,
      [`${kind}_hash`]: hash,
      // Which store holds the staged copy, so promotion reads the bucket (#332).
      [`${kind}_staged_tier`]: "bucket",
    })
    .eq("id", owned.id);
```

and the success message:

```ts
  return { ok: true, message: `${IMAGE_LABELS[kind]} uploaded.` };
```

In `removeGameImage`, swap its `kind !== "logo" && kind !== "banner"` guard for `!isGameImageKind(kind)` and its final message for `` `${IMAGE_LABELS[kind]} removed.` ``.

Add `isGameImageKind` and `type GameImageKind` to the imports from `@/lib/api/gameBranding`.

- [ ] **Step 7: Teach promotion about the third picture**

In `lib/assets/promoteGameImages.ts`, replace the hand-listed interface:

```ts
/** What the run reads off a game row: three columns per picture kind. */
type GameImagePaths = { shortname: string } & Record<string, string | null>;

/** Every column the two reads below ask for, built from the kind list so a
 *  fourth picture is one entry in that list and nothing here. */
const GAME_IMAGE_PATH_COLUMNS = GAME_IMAGE_KINDS.map((kind) => `${kind}_path`);
const GAME_IMAGE_ALL_COLUMNS = GAME_IMAGE_KINDS.flatMap((kind) => [
  `${kind}_path`,
  `${kind}_hash`,
  `${kind}_staged_tier`,
]);
```

change `GameImage.kind` to `GameImageKind`, and rewrite the two loops. In `strayGameImages`:

```ts
  for (const row of rows) {
    for (const kind of GAME_IMAGE_KINDS) {
      const path = row[`${kind}_path`];
      if (path) named.add(path);
      for (const ext of GAME_IMAGE_EXTENSIONS) candidates.add(`games/${row.shortname}/${kind}.${ext}`);
    }
  }
```

In `fetchStagedGameImages`:

```ts
  for (const row of (data ?? []) as unknown as GameImagePaths[]) {
    for (const kind of GAME_IMAGE_KINDS) {
      const path = row[`${kind}_path`];
      const hash = row[`${kind}_hash`];
      if (path && hash && row[`${kind}_staged_tier`] === "bucket") {
        out.push({ shortname: row.shortname, kind, path, hash });
      }
    }
  }
```

with the two `select` calls becoming `.select(["shortname", ...GAME_IMAGE_PATH_COLUMNS].join(","))` and `.select(["shortname", ...GAME_IMAGE_ALL_COLUMNS].join(","))`.

Run `bun test lib/assets/promoteGameImages.test.ts` after this step. Its existing tests cover logo and banner and must pass without being edited.

- [ ] **Step 8: Carry the columns through the page loader**

In `lib/games/page.ts`, add `card_path`, `card_hash` and `card_staged_tier` to both interfaces (around lines 45-50 and 61-66), to the `select` string (line 85) and to the returned object (around line 121). Follow the banner triple exactly.

- [ ] **Step 9: Add the upload form and draw the art**

In `app/games/[shortname]/edit/GameImageForm.tsx`, widen the prop type and the button label:

```tsx
export function GameImageForm({ shortname, kind }: { shortname: string; kind: GameImageKind }) {
```

```tsx
          {pending ? "Uploading…" : `Upload ${UPLOAD_BUTTON_LABELS[kind]}`}
```

```tsx
const UPLOAD_BUTTON_LABELS: Record<GameImageKind, string> = {
  logo: "logo",
  banner: "banner",
  card: "card art",
};
```

Widen the `kind` prop on `app/games/[shortname]/edit/GameImageRemoveForm.tsx` the same way.

In `app/games/[shortname]/edit/page.tsx`, add a section after the Banner one:

```tsx
        <section className="flex flex-col gap-3 border-t border-neutral-900 pt-6">
          <h2 className="text-sm uppercase tracking-wide text-neutral-400">Card art</h2>
          <p className="text-sm text-neutral-500">
            16:9, PNG or WebP, up to 512 KB. This is what the games listing draws above the name,
            and what a lobby shows on its own card for the game.
          </p>
          <GameImageForm shortname={shortname} kind="card" />
          <GameImageRemoveForm shortname={shortname} kind="card" present={page.card_path !== null} />
        </section>
```

and update that page's doc comment, which says "Three forms" and "two images", to say four and three.

In `components/GameCard.tsx`, resolve the art:

```tsx
  const card = gameArtUrl(game.shortname, "card", {
    path: game.card_path,
    hash: game.card_hash,
    staged_tier: game.card_staged_tier,
  });
```

and draw it as the first child of the `<li>`, before the `<div className="flex items-start gap-4">`:

```tsx
      {card ? (
        // Decorative: the name under it says which game this is. A fixed 16:9
        // box so a row of cards lines up whatever each game uploaded, and the
        // picture is cropped rather than letterboxed, because a band of
        // background inside a card reads as a mistake.
        <img
          src={card}
          alt=""
          className="-mx-4 -mt-4 aspect-video w-[calc(100%+2rem)] rounded-t-md object-cover"
        />
      ) : null}
```

Extend that component's doc comment to say what the art is and that a game without it keeps the old layout.

- [ ] **Step 10: Verify**

Run: `bun test && bun run lint && bun run typecheck && bun run build`
Expected: exit 0 from all four. `lib/games/art.test.ts`, `lib/games/imageUpload.test.ts`, `lib/games/imageResize.test.ts`, `lib/assets/promoteGameImages.test.ts` and `app/games/editing.test.ts` must all pass without being edited. If any needed editing to pass, stop and report it: that is a behaviour change this task did not intend.

- [ ] **Step 11: Commit**

```bash
git add lib/api/gameBranding.ts lib/games/art.ts lib/games/imageResize.ts lib/games/imageUpload.ts lib/games/imageKinds.test.ts lib/games/page.ts lib/assets/promoteGameImages.ts app/games/actions.ts components/GameCard.tsx "app/games/[shortname]/edit"
git commit -m "Give a game a third picture, the 16:9 art its card is drawn from

The kinds were a two-value union repeated as literals in six files, and the
upload path read anything that was not a logo as a banner, so a third kind had
to become a list before it could become a feature."
```

---

### Task 6: Download source

**Files:**
- Create: `lib/games/download.ts`
- Create: `lib/games/download.test.ts`
- Modify: `app/games/actions.ts` (`editGameDetails`, lines 173-213)
- Modify: `app/games/[shortname]/edit/GameDetailsForm.tsx`
- Modify: `app/games/[shortname]/edit/page.tsx`
- Modify: `lib/games/page.ts`
- Modify: `app/games/[shortname]/page.tsx`

**Interfaces:**
- Consumes: `download_kind` and `download_value` from Task 3, and the widened `authenticated` update grant. `OWNER_CONTROL` from Task 2.
- Produces: `DOWNLOAD_KINDS: readonly ["rapid", "url", "github"]`, `DownloadKind`, `GameDownload` as `{ kind: DownloadKind; value: string }`, `parseDownload(kind: string, value: string): ParsedDownload` where `ParsedDownload` is `{ ok: true; download: GameDownload | null } | { ok: false; message: string }`, and `downloadHref(download: GameDownload): string | null`.

- [ ] **Step 1: Write the failing validator test**

Create `lib/games/download.test.ts`:

```ts
import { expect, test } from "bun:test";
import { downloadHref, DOWNLOAD_KINDS, parseDownload } from "./download";

test("the three kinds match the check constraint", () => {
  expect(DOWNLOAD_KINDS).toEqual(["rapid", "url", "github"]);
});

test("an empty form clears the download", () => {
  expect(parseDownload("", "")).toEqual({ ok: true, download: null });
  // A kind picked but nothing typed is somebody who changed their mind, not a
  // mistake worth stopping a save for.
  expect(parseDownload("rapid", "   ")).toEqual({ ok: true, download: null });
});

test("a rapid tag is game:branch", () => {
  expect(parseDownload("rapid", "metalfactions:stable")).toEqual({
    ok: true,
    download: { kind: "rapid", value: "metalfactions:stable" },
  });
  expect(parseDownload("rapid", "metalfactions").ok).toBe(false);
  expect(parseDownload("rapid", "a:b:c").ok).toBe(false);
});

test("a URL must be http or https", () => {
  expect(parseDownload("url", "https://example.test/game.sdz")).toEqual({
    ok: true,
    download: { kind: "url", value: "https://example.test/game.sdz" },
  });
  expect(parseDownload("url", "ftp://example.test/game.sdz").ok).toBe(false);
  expect(parseDownload("url", "not a url").ok).toBe(false);
  // No script URL ever becomes an href on a page the hub serves.
  expect(parseDownload("url", "javascript:alert(1)").ok).toBe(false);
});

test("a github value is owner/repo", () => {
  expect(parseDownload("github", "Balanced-Annihilation/Balanced-Annihilation")).toEqual({
    ok: true,
    download: { kind: "github", value: "Balanced-Annihilation/Balanced-Annihilation" },
  });
  expect(parseDownload("github", "https://github.com/owner/repo").ok).toBe(false);
  expect(parseDownload("github", "owner").ok).toBe(false);
  expect(parseDownload("github", "owner/repo/extra").ok).toBe(false);
});

test("an unknown kind is refused", () => {
  expect(parseDownload("torrent", "whatever").ok).toBe(false);
});

test("a value longer than the column allows is refused", () => {
  expect(parseDownload("url", `https://example.test/${"a".repeat(600)}`).ok).toBe(false);
});

test("only two of the three kinds have somewhere to click", () => {
  expect(downloadHref({ kind: "url", value: "https://example.test/g.sdz" })).toBe(
    "https://example.test/g.sdz",
  );
  expect(downloadHref({ kind: "github", value: "owner/repo" })).toBe(
    "https://github.com/owner/repo/releases",
  );
  // A rapid tag is a string a lobby consumes. There is nothing to open.
  expect(downloadHref({ kind: "rapid", value: "ba:stable" })).toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `bun test lib/games/download.test.ts`
Expected: FAIL, the module does not exist.

- [ ] **Step 3: Write the validators**

Create `lib/games/download.ts`:

```ts
/**
 * Where a lobby fetches a game from, and whether what somebody typed is a
 * thing of that kind.
 *
 * Three kinds, and the set is closed, which is why the kind is a column with a
 * check constraint on it rather than another entry in the `links` blob. The
 * shapes are the ones coilbox's own `catalog.json` already carries: a rapid
 * tag as `{"kind": "rapid", "tag": "metalfactions:stable"}`, and a repo as the
 * `owner/repo` its `githubGameRepos` entries hold.
 *
 * The format lives here rather than in the check constraint because these are
 * strings a person types into a form, and a constraint violation is not a
 * sentence anybody can act on. The constraint still holds the kind and the
 * length, so a write that skipped this file cannot store nonsense.
 */

export const DOWNLOAD_KINDS = ["rapid", "url", "github"] as const;

export type DownloadKind = (typeof DOWNLOAD_KINDS)[number];

export interface GameDownload {
  kind: DownloadKind;
  value: string;
}

export type ParsedDownload =
  | { ok: true; download: GameDownload | null }
  | { ok: false; message: string };

/** What the column holds. Longer than any real tag, address or repo path, and
 *  short enough that a paste of something else is refused here rather than by
 *  the database. */
const MAX_VALUE = 512;

/** One colon between two non-empty halves, which is how every tag in
 *  coilbox's catalog reads. */
const RAPID = /^[^\s:]+:[^\s:]+$/;

/** GitHub's own rule for both halves: letters, digits, dot, dash and
 *  underscore, with exactly one slash between them. */
const REPO = /^[\w.-]+\/[\w.-]+$/;

function refuse(message: string): ParsedDownload {
  return { ok: false, message };
}

/**
 * Read the kind and value a form posted.
 *
 * A blank value clears the download, whatever the kind says, because a select
 * left on its default beside an empty box is somebody who did not fill this in
 * rather than somebody who got it wrong.
 */
export function parseDownload(kind: string, value: string): ParsedDownload {
  const trimmed = value.trim();
  if (trimmed === "") return { ok: true, download: null };

  if (trimmed.length > MAX_VALUE) {
    return refuse(`A download is at most ${MAX_VALUE} characters. That one is ${trimmed.length}.`);
  }

  if (kind === "rapid") {
    if (!RAPID.test(trimmed)) {
      return refuse('A rapid tag looks like "metalfactions:stable" - a name, a colon, then a branch.');
    }
    return { ok: true, download: { kind, value: trimmed } };
  }

  if (kind === "url") {
    let parsed: URL;
    try {
      parsed = new URL(trimmed);
    } catch {
      return refuse('That is not a web address. It should start with "https://".');
    }
    // Anything else, javascript: above all, would become an href on a page the
    // hub serves to everybody.
    if (parsed.protocol !== "https:" && parsed.protocol !== "http:") {
      return refuse("A download address has to be http or https.");
    }
    return { ok: true, download: { kind, value: trimmed } };
  }

  if (kind === "github") {
    if (!REPO.test(trimmed)) {
      return refuse('A GitHub repo looks like "owner/repo", not a full address.');
    }
    return { ok: true, download: { kind, value: trimmed } };
  }

  return refuse("Pick how the game is downloaded.");
}

/**
 * Where the download control points, or null when there is nowhere to point.
 *
 * A rapid tag has no URL. It is a string a lobby hands to its own downloader,
 * and dressing it as a link would tell a reader it does something it does not.
 */
export function downloadHref(download: GameDownload): string | null {
  if (download.kind === "url") return download.value;
  if (download.kind === "github") return `https://github.com/${download.value}/releases`;
  return null;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `bun test lib/games/download.test.ts`
Expected: PASS, 8 tests.

- [ ] **Step 5: Save it from the edit form**

In `app/games/actions.ts`, inside `editGameDetails`, after `displayName` is read:

```ts
  const download = parseDownload(
    String(form.get("download_kind") ?? ""),
    String(form.get("download_value") ?? ""),
  );
  if (!download.ok) return { ok: false, message: download.message };
```

and extend the update:

```ts
    .update({
      display_name: displayName || null,
      description: String(form.get("description") ?? "").trim().slice(0, 4000) || null,
      links: linksFromForm(form),
      download_kind: download.download?.kind ?? null,
      download_value: download.download?.value ?? null,
    })
```

Add the import:

```ts
import { parseDownload } from "@/lib/games/download";
```

The write still uses the visitor's own client. Task 3's migration widened the `authenticated` column grant to cover both columns, and the `game_edit_owner` policy already decides which row.

- [ ] **Step 6: Add the two controls to the form**

In `app/games/[shortname]/edit/GameDetailsForm.tsx`, add two props:

```tsx
  downloadKind,
  downloadValue,
}: {
  shortname: string;
  displayName: string;
  description: string;
  links: GameLink[];
  downloadKind: string;
  downloadValue: string;
}) {
```

and a fieldset after the Links one:

```tsx
      <fieldset className="flex flex-col gap-2">
        <legend className={LABEL}>Download</legend>
        <p className="text-xs text-neutral-500">
          How a lobby fetches this game. A rapid tag looks like{" "}
          <code className="font-mono">metalfactions:stable</code>, a GitHub repo like{" "}
          <code className="font-mono">owner/repo</code>, and an address starts with https.
        </p>
        <div className="flex gap-2">
          <select
            name="download_kind"
            defaultValue={downloadKind}
            aria-label="How the game is downloaded"
            className={`${CONTROL} w-40`}
          >
            <option value="rapid">Rapid tag</option>
            <option value="url">Address</option>
            <option value="github">GitHub repo</option>
          </select>
          <input
            name="download_value"
            defaultValue={downloadValue}
            maxLength={512}
            placeholder="metalfactions:stable"
            aria-label="Download tag, address or repo"
            className={CONTROL}
          />
        </div>
      </fieldset>
```

In `app/games/[shortname]/edit/page.tsx`, pass them:

```tsx
        <GameDetailsForm
          shortname={shortname}
          displayName={page.display_name ?? ""}
          description={page.description ?? ""}
          links={page.links}
          downloadKind={page.download_kind ?? "rapid"}
          downloadValue={page.download_value ?? ""}
        />
```

In `lib/games/page.ts`, add `download_kind` and `download_value` the same way Task 5 added the card columns: both interfaces, the `select` string, and the returned object.

- [ ] **Step 7: Draw it on the game page**

In `app/games/[shortname]/page.tsx`, after the release line and before the owner controls:

```tsx
          {page.download_kind && page.download_value ? (
            <Download
              download={{ kind: page.download_kind as DownloadKind, value: page.download_value }}
            />
          ) : null}
```

with, below the page component:

```tsx
/** Where to get the game, which is the one thing a visitor who does not have
 *  it yet is looking for. A rapid tag is drawn as text rather than a link,
 *  because there is nowhere for it to go: it is a string a lobby hands to its
 *  own downloader. */
function Download({ download }: { download: GameDownload }) {
  const href = downloadHref(download);
  if (!href) {
    return (
      <p className="text-sm text-neutral-400">
        Install with rapid:{" "}
        <code className="rounded bg-neutral-900 px-2 py-1 font-mono text-neutral-200">
          {download.value}
        </code>
      </p>
    );
  }
  return (
    <p className="text-sm">
      <a href={href} className={OWNER_CONTROL}>
        {download.kind === "github" ? "Releases on GitHub" : "Download this game"}
      </a>
    </p>
  );
}
```

Add the imports:

```tsx
import { downloadHref, type DownloadKind, type GameDownload } from "@/lib/games/download";
```

`OWNER_CONTROL` is the class Task 2 added to this file. A download is not an owner control, but it is the same kind of button, and a second near-identical string here would be the problem Task 1 just cleared up. If the name reads wrong once both are in place, rename it to `CONTROL_BUTTON` in this task and update Task 2's two uses.

- [ ] **Step 8: Verify**

Run: `bun test && bun run lint && bun run typecheck && bun run build`
Expected: exit 0 from all four.

- [ ] **Step 9: Commit**

```bash
git add lib/games/download.ts lib/games/download.test.ts lib/games/page.ts app/games/actions.ts "app/games/[shortname]"
git commit -m "Say where a game is downloaded from

A visitor who does not have the game yet had nowhere to go from its page, and a
lobby had nothing to act on. Three kinds because that is what coilbox's catalog
already distinguishes, and a rapid tag is drawn as text because it is a string
a downloader consumes rather than somewhere to click."
```

---

### Task 7: The public games API

**Files:**
- Create: `lib/api/gameList.ts`
- Create: `lib/api/gameList.test.ts`
- Create: `app/api/v1/games/route.ts`

**Interfaces:**
- Consumes: `fetchGames` and `GameSummary` from `lib/games/query.ts`, `gameArtUrl` from `lib/games/art.ts`, `gameTitle` from `lib/games/labels.ts`, `DownloadKind` from `lib/games/download.ts`, `apiJson` and `apiError` from `lib/api/response.ts`, `corsPreflight` from `lib/api/cors.ts`, `createAnonClient` from `lib/supabase/anon.ts`.
- Produces: `buildGameListBody(games: GameSummary[]): GameListResponseBody`.

Note before starting: the banner columns are not on `GameSummary`, only on `GamePage`. This route publishes `logo` and `card` and not `banner`. Publishing a banner would mean adding three more columns to the view and to `GameSummary`, which is not in this plan. Do not add a `banner` field that is structurally always null.

- [ ] **Step 1: Write the failing body test**

Create `lib/api/gameList.test.ts`:

```ts
import { expect, test } from "bun:test";
import { staticTierUrl } from "@/lib/assets/cdn";
import type { GameSummary } from "@/lib/games/query";
import { buildGameListBody, GAME_LIST_FORMAT, GAME_LIST_VERSION } from "./gameList";

function game(fields: Partial<GameSummary> & Pick<GameSummary, "shortname">): GameSummary {
  return {
    display_name: null,
    description: null,
    logo_path: null,
    logo_hash: null,
    logo_staged_tier: null,
    card_path: null,
    card_hash: null,
    card_staged_tier: null,
    featured_at: null,
    download_kind: null,
    download_value: null,
    faction_count: 0,
    unit_count: 0,
    item_count: 0,
    ...fields,
  };
}

test("the envelope names the same format the other game routes use", () => {
  const body = buildGameListBody([]);
  expect(body.format).toBe(GAME_LIST_FORMAT);
  expect(body.version).toBe(GAME_LIST_VERSION);
  expect(GAME_LIST_FORMAT).toBe("coilbox-hub-games");
  expect(body.games).toEqual([]);
});

test("a bare game answers nulls rather than being left out", () => {
  const [row] = buildGameListBody([game({ shortname: "BA" })]).games;
  expect(row).toEqual({
    shortname: "BA",
    title: "BA",
    description: null,
    featured: false,
    download: null,
    logo: null,
    card: null,
    faction_count: 0,
    unit_count: 0,
    item_count: 0,
  });
});

test("the title falls back to the shortname the way every page does", () => {
  const [row] = buildGameListBody([
    game({ shortname: "BAR", display_name: "Beyond All Reason" }),
  ]).games;
  expect(row.title).toBe("Beyond All Reason");
});

test("a download is published as the kind and the value, not as an address", () => {
  const [row] = buildGameListBody([
    game({ shortname: "MF", download_kind: "rapid", download_value: "metalfactions:stable" }),
  ]).games;
  // The caller decides what to do with a rapid tag. Handing it a link here
  // would mean inventing one.
  expect(row.download).toEqual({ kind: "rapid", value: "metalfactions:stable" });
});

test("promoted art is published as its durable address", () => {
  const [row] = buildGameListBody([
    game({ shortname: "BA", card_path: "games/BA/card.webp" }),
  ]).games;
  expect(row.card).toBe(staticTierUrl("games/BA/card.webp"));
});

test("featured is a flag, not a timestamp", () => {
  const [row] = buildGameListBody([
    game({ shortname: "BA", featured_at: "2026-09-18T00:00:00Z" }),
  ]).games;
  expect(row.featured).toBe(true);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `bun test lib/api/gameList.test.ts`
Expected: FAIL, the module does not exist.

- [ ] **Step 3: Build the body**

Create `lib/api/gameList.ts`:

```ts
import { gameArtUrl } from "@/lib/games/art";
import type { DownloadKind } from "@/lib/games/download";
import { gameTitle } from "@/lib/games/labels";
import type { GameSummary } from "@/lib/games/query";

/**
 * The wire shape of the games listing.
 *
 * The facts route takes a game's numbers in and the branding route takes its
 * pictures in. This is the read the other way: every game the hub holds, with
 * enough on each to draw a card and fetch the game. A lobby drawing a "games
 * you could install" screen has had to scrape the HTML listing until now.
 *
 * The envelope is `coilbox-hub-games`, the same string both submission routes
 * carry. All three talk to the same client about the same games, and a second
 * format negotiation buys nothing.
 *
 * ## Why no token
 *
 * Every field here describes a page `/games` already serves in public. A
 * listing that demanded an account would put a lobby's download screen behind
 * a sign in, and there is no account at that rung of coilbox's ladder.
 *
 * ## Why the download is a kind and a value
 *
 * Resolving a rapid tag to an address is not something the hub can do. Its
 * caller hands the tag to its own downloader, so the honest answer is the pair
 * that was stored and the caller decides what a kind means.
 *
 * ## One limit worth knowing
 *
 * A picture still waiting for promotion resolves to a root relative path
 * (`/assets/games/...`), which an external caller cannot fetch as it stands.
 * That is the same window the hub's own pages live with between an upload and
 * the next promotion run. It is left as it is rather than made absolute,
 * because this layer does not know the hub's public origin and guessing one
 * would be worse than a path the caller can resolve against the host it just
 * called.
 */

export const GAME_LIST_FORMAT = "coilbox-hub-games";
export const GAME_LIST_VERSION = 1;

export interface GameListEntry {
  shortname: string;
  /** What to print: the display name where one is held, the shortname
   *  otherwise, which is the fallback every page here already makes. */
  title: string;
  description: string | null;
  featured: boolean;
  download: { kind: DownloadKind; value: string } | null;
  /** Resolved addresses, so a caller never has to know about tiers or
   *  staging. */
  logo: string | null;
  card: string | null;
  faction_count: number;
  unit_count: number;
  item_count: number;
}

export interface GameListResponseBody {
  format: typeof GAME_LIST_FORMAT;
  version: typeof GAME_LIST_VERSION;
  games: GameListEntry[];
}

export function buildGameListBody(games: GameSummary[]): GameListResponseBody {
  return {
    format: GAME_LIST_FORMAT,
    version: GAME_LIST_VERSION,
    games: games.map((game) => ({
      shortname: game.shortname,
      title: gameTitle(game),
      description: game.description,
      featured: game.featured_at !== null,
      download:
        game.download_kind && game.download_value
          ? { kind: game.download_kind as DownloadKind, value: game.download_value }
          : null,
      logo: gameArtUrl(game.shortname, "logo", {
        path: game.logo_path,
        hash: game.logo_hash,
        staged_tier: game.logo_staged_tier,
      }),
      card: gameArtUrl(game.shortname, "card", {
        path: game.card_path,
        hash: game.card_hash,
        staged_tier: game.card_staged_tier,
      }),
      faction_count: game.faction_count,
      unit_count: game.unit_count,
      item_count: game.item_count,
    })),
  };
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `bun test lib/api/gameList.test.ts`
Expected: PASS, 6 tests.

- [ ] **Step 5: Write the route**

Create `app/api/v1/games/route.ts`:

```ts
import { corsPreflight } from "@/lib/api/cors";
import { buildGameListBody } from "@/lib/api/gameList";
import { apiError, apiJson } from "@/lib/api/response";
import { fetchGames } from "@/lib/games/query";
import { createAnonClient } from "@/lib/supabase/anon";

/**
 * Every game the hub holds, as the listing page holds it.
 *
 * The same `fetchGames` the page calls, through the same anonymous client, so
 * the page and this route cannot come to disagree about what is published or
 * in what order. `public.game_browse` is security invoker over tables with
 * read-all policies, so a hidden game is invisible here exactly as it is on
 * the page, without this route knowing hiding exists.
 *
 * ## A failed read is a 503, not an empty list
 *
 * An empty list is a claim: the hub holds no games. A caller that acted on a
 * claim the hub could not actually make would stop asking. `/api/v1/maps/lookup`
 * records the same reasoning for the same choice.
 */
export const OPTIONS = corsPreflight;

export async function GET() {
  const { games, error } = await fetchGames(createAnonClient());
  if (error) {
    console.error("GET /api/v1/games: the catalog could not be read", error);
    return apiError("The catalog could not be read just now.", 503);
  }
  return apiJson(buildGameListBody(games));
}
```

- [ ] **Step 6: Verify the route answers**

Run:

```bash
bun run build
bun run dev &
sleep 5
curl -sS localhost:3000/api/v1/games | head -c 400
```

Expected: a JSON body whose `format` is `coilbox-hub-games` and whose `games` array holds the same games `/games` lists, featured ones first. Stop the dev server afterwards.

If this environment has no Supabase configured, the answer is the 503 instead. That is the correct answer for that state and it still proves the route is wired. Say which of the two happened in the task report rather than implying the list was seen.

- [ ] **Step 7: Verify the whole suite**

Run: `bun test && bun run lint && bun run typecheck && bun run build`
Expected: exit 0 from all four.

- [ ] **Step 8: Commit**

```bash
git add app/api/v1/games/route.ts lib/api/gameList.ts lib/api/gameList.test.ts
git commit -m "Publish the games listing as JSON

A lobby drawing a games screen had to scrape the HTML listing. It shares
fetchGames with the page rather than running its own query, so the two cannot
come to disagree about what is published or in what order."
```

---

## After the tasks

- [ ] **Screenshots, before any pull request**

The user asked to see the new card shade before the PR is opened. Use the `agent-browser` skill. Capture at least:

- `/games` with a featured game and without one, so the divider and the card art are both visible.
- `/games/<shortname>` for a game with a download source, showing the two owner controls side by side.
- One moderation page, as a card surface away from the games section.

Show the shade and wait for a verdict on it before opening anything.

- [ ] **Apply the migration**

`CLAUDE.md` says to check whether the migration can be applied from here rather than handing it back. Run `supabase migration list --linked` to see whether production is reachable and what is pending, report what the pending list holds, and run `supabase db push --linked` only once the owner has asked for the change to ship.

- [ ] **File the pull request**

Through the `file-pr` skill, every time, without exception.

## Issue numbers

This repo's comments cite issue numbers heavily, and every comment written by this plan omits them rather than inventing one. Before the branch is filed, ask which issues cover this work and add the references, or agree to leave them out. A made-up number is worse than none.
