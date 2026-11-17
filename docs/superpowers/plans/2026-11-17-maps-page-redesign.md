# Maps page redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `/maps` a Coilbox-style filter bar, a grid that adds columns as the screen widens, and Featured and Newest uploads rows on the unfiltered first page.

**Architecture:** One migration appends `uploaded_at` and `play_types` to `public.map_browse`, with `uploaded_at` read through a `security definer` function because `public.archive` grants nothing to API roles. `App\Domain\Maps\Listing` learns the new params, chips and the two sections. The Blade view is split into a filter bar, a section row and the grid, and the card is restyled.

**Tech Stack:** Postgres views and pgTAP (`supabase test db`), Laravel 12 Blade, Tailwind v4, PHPUnit with `Http::fake()`.

**Spec:** `docs/superpowers/specs/2026-11-17-maps-page-redesign-design.md`

## Global Constraints

- Change only `hub-php/` and `supabase/`. The Next.js app in the repository root is not touched.
- Coilbox rules: `1v1` = exactly 2 start positions or a `1v1` tag (case-insensitive). `team` = 4 or more start positions.
- Terrain chips set the tags `water map`, `tidal`, `windy`.
- `PAGE_SIZE` = 60. Section size = 12. Grid minimum card width = 200px, gap = 16px (`gap-4`).
- Every page must work with scripting off. JS only adds sort-submits-on-change and the existing `/` shortcut.
- Subagents never run `scripts/local-services.sh`, `php artisan serve` or `supabase start`. The lead starts services.
- The local PHP app at `http://localhost:8100` reads the production database. Never send a write to it.
- `create or replace view` needs new columns appended at the end, and grants repeated after it.

## Review Focus

1. Section cards hidden past the first row must not be reachable by Tab. Hiding them with `overflow: hidden` leaves their links focusable. Task 4 hides them with `display: none` through container queries, and its feature test asserts the CSS class is present.
2. An old bookmark like `/maps?tag=metal&size=small&sort=added&page=2` must still work. Task 2 asserts it parses and round-trips through `filterHref`.
3. A card tag chip clicked while other tags are active must add that tag, not replace them. If it is already active, the link must not duplicate it. Task 2 tests `filterHref` with a tag list.
4. Before the migration reaches production, `/maps` must still render. The default listing must not select or filter the new columns, and a failed section read must only drop that section. Task 3 tests a 503 on the section read.
5. `sort=uploaded` with nothing uploaded must show the "No map matches that." empty state, not "The hub holds facts about no maps yet.". This works because `isFiltered` counts `sort=uploaded`. Task 4 asserts it.

---

### Task 1: `map_browse` gains `uploaded_at` and `play_types`

**Files:**
- Create: `supabase/migrations/20261117150000_map_browse_upload_and_play.sql`
- Create: `supabase/tests/map_browse_upload_and_play.test.sql`
- Possibly modify: `supabase/tests/function_privileges.test.sql`, if it lists every public function

**Interfaces:**
- Produces: `public.map_uploaded_at(p_map_name text) returns timestamptz`, `language sql stable security definer set search_path = ''`. It returns `max(listed_at)` from `public.archive` where `kind = 'map' and state = 'listed' and internal_name = p_map_name`. Execute is revoked from `public` and granted to `anon, authenticated, service_role`.
- Produces: `map_browse.uploaded_at timestamptz` (null when never uploaded) and `map_browse.play_types text[]` (never null, `'{}'` when neither applies), appended after `featured_at`.

The lead runs `scripts/local-services.sh status`, then `scripts/local-services.sh up supabase` if Supabase is not running, before this task starts.

- [ ] **Step 1: Write the failing pgTAP test.** Copy the setup style of `supabase/tests/map_featured.test.sql` (begin, plan, insert into `public.map`, run as `anon` with `set local role anon`). Seed four maps and their `public.map_point` start rows: A has 2 starts, B has 4 starts, C has 6 starts and curated tag `1V1`, D has 0 starts. Seed `public.archive` rows for A: one `state = 'listed'` with `listed_at = '2026-11-10'`, one `state = 'listed'` with `listed_at = '2026-11-12'`, one `state = 'removed'` with `listed_at = '2026-11-15'`. Also seed one `kind = 'game'` archive with `internal_name` equal to B's map name. Assertions, as `anon`:
  - A `play_types = '{1v1}'`, B `= '{team}'`, C `= '{1v1,team}'`, D `= '{}'`
  - A `uploaded_at = '2026-11-12'`, B `uploaded_at is null` (the game archive does not count), D `is null`
  - `select * from public.archive` as `anon` throws `42501`

- [ ] **Step 2: Run it and see it fail.** Run `supabase test db supabase/tests/map_browse_upload_and_play.test.sql`. Expected: fails with `column "play_types" does not exist`.

- [ ] **Step 3: Write the migration.** Create the function first. Then `create or replace view public.map_browse` with the body of `20260918150000_map_featured.sql` copied unchanged, and append after `map.featured_at`:
  - `public.map_uploaded_at(listing.map_name) as uploaded_at`
  - `array_remove(array[case when coalesce(start_point.positions, 0) = 2 or exists (select 1 from unnest(listing.tags) as t(tag) where lower(t.tag) = '1v1') then '1v1' end, case when coalesce(start_point.positions, 0) >= 4 then 'team' end], null) as play_types`

  Then repeat the view grants from that migration. The header comment says why the function exists (archive grants nothing to API roles) and that the rules are Coilbox's `mapPlayTypes`.

- [ ] **Step 4: Apply and test.** Run `supabase migration up --local`, then `supabase test db supabase/tests/map_browse_upload_and_play.test.sql`. Expected: all pass. Then run `supabase test db supabase/tests/map_browse.test.sql supabase/tests/map_featured.test.sql supabase/tests/function_privileges.test.sql supabase/tests/table_privileges.test.sql`. If `function_privileges` fails only because it lists every public function, add `map_uploaded_at` there with the grants above. Any other failure stops the task.

- [ ] **Step 5: Commit**

```bash
git add supabase/migrations/20261117150000_map_browse_upload_and_play.sql supabase/tests/map_browse_upload_and_play.test.sql
git commit -m "Add upload date and play types to the map browse view"
```

---

### Task 2: Listing params, chips and links

**Files:**
- Modify: `hub-php/app/Domain/Maps/Listing.php`
- Test: `hub-php/tests/Unit/Maps/PageLogicTest.php`

**Interfaces:**
- Consumes: the view columns from Task 1 (`uploaded_at`, `play_types`).
- Produces:
  - Filters array shape: `{q: ?string, tag: list<string>, author: ?string, size: ?string, players: ?int, play: list<'1v1'|'team'>, featured: bool, sort: string, page: int}`
  - `Listing::FILTER_KEYS = ['q', 'tag', 'author', 'size', 'players', 'play', 'featured', 'sort', 'page']`. `filterHref` writes keys in this order, and `tag` and `play` once per value.
  - `Listing::SORTS = ['name', 'size', 'players', 'added', 'uploaded']`, with `'uploaded' => ['uploaded_at', false]`
  - `Listing::PLAY_TYPES = ['1v1', 'team']`
  - `Listing::PAGE_SIZE = 60`
  - `Listing::chips(array $filters): list<array{label: string, href: string, active: bool}>`, in the order Featured, New uploads, 1v1, Team, Water, Tidal, Windy
  - `Listing::isLanding(array $filters): bool`, true when `! isFiltered`, `page === 1` and `sort === 'name'`

- [ ] **Step 1: Update and add the failing tests** in `PageLogicTest.php`:
  - `test_filters_come_out_of_the_query_string`: `tag` comes out as `['water map']`, and the new keys come out as `'play' => [], 'featured' => false`.
  - `test_unknown_and_nonsense_values_fall_back`: `parseFilters(['play' => ['1v1', 'ffa', '1v1']])['play'] === ['1v1']`. `parseFilters(['featured' => '0'])['featured'] === false` and `parseFilters(['featured' => '1'])['featured'] === true`. `parseFilters(['tag' => ['Water Map', 'water map', 'small']])` gives `tag === ['water map']` and `size === 'small'`. A blank tag gives `[]`. `sort=uploaded` is kept.
  - `test_links`: keep every existing assertion, adjusting the `$filters` literal at line 81 to the new shape, with `'tag' => ['water']`, `'play' => ['team']` and `'featured' => true`, and assert `array_keys` of the parsed href equals `FILTER_KEYS`. Add these:
    - `filterHref(parseFilters(['tag' => ['tidal', 'windy']]), [])` is `/maps?tag=tidal&tag=windy`.
    - The old bookmark `parseFilters(['tag' => 'metal', 'size' => 'small', 'sort' => 'added', 'page' => '2'])` with `['page' => 2]` gives `/maps?tag=metal&size=small&sort=added&page=2`.
    - `isFiltered` is true for `featured=1`, `play=1v1` and `sort=uploaded`.
    - `isLanding` is true for `[]`, and false for `page=2`, `sort=size` and `featured=1`.
  - New `test_chips`: from `parseFilters(['play' => '1v1', 'tag' => 'tidal'])`, the labels are `['Featured', 'New uploads', '1v1', 'Team', 'Water', 'Tidal', 'Windy']` and the active flags are `[false, false, true, false, false, true, false]`. These hrefs must hold:
    - The 1v1 chip is `/maps?tag=tidal`, which turns it off.
    - The Team chip is `/maps?tag=tidal&play=1v1&play=team`.
    - The Water chip is `/maps?tag=tidal&tag=water+map&play=1v1`.
    - New uploads is `...&sort=uploaded`. From `sort=uploaded`, its href drops `sort`.
    - Featured adds `featured=1`.
    - Every chip href drops `page`.
  - `test_filters_and_orders_become_one_query`: the first query has `tags=cs.{"water map",small}`. Keep the separate size `contains` if simpler, and assert whatever the code sends exactly. The order has no `featured_at` term: `order=longer_edge_elmos.desc,map_name.asc`. The second is `order=map_name.asc`. Add a third query from `parseFilters(['featured' => '1', 'play' => ['1v1', 'team'], 'sort' => 'uploaded'])`, asserting `featured_at=not.is.null`, `play_types=ov.{1v1,team}`, `uploaded_at=not.is.null` and `order=uploaded_at.desc,map_name.asc`.

- [ ] **Step 2: Run and see them fail.** Run `cd hub-php && php artisan test --filter=PageLogicTest`. Expected: failures on the new keys and orders.

- [ ] **Step 3: Implement in `Listing.php`.**
  - `parseFilters` reads `tag` through a new private `many()`, which trims, lowercases, drops blanks and dedupes. The first size band found among the tags moves to `size` when `size` is null, as today.
  - `play` keeps only `PLAY_TYPES`, deduped. `featured` is true only for the value `1`.
  - `applyFilters` sends tags as one `contains('tags', $tags)`. `featured` sends `not('featured_at', 'is', 'null')`, `play` sends `overlaps('play_types', $play)`, and `sort === 'uploaded'` sends `not('uploaded_at', 'is', 'null')`. `applyFilters` takes the filters array, so the sort is visible there.
  - `applyOrder` drops the `featured_at` line and updates its docblock.
  - `filterHref` writes keys in `FILTER_KEYS` order, a pair per list value, and `featured=1` when true. The card's tag link calls `filterHref($filters, ['tag' => [...$filters['tag'], $tag]])` deduped, so put a small `withTag(array $filters, string $tag): array` change helper beside `chips` and use it from both.
  - Update the docblocks that mention "ten rows of five" and the filter list.

- [ ] **Step 4: Run and see them pass.** Run `cd hub-php && php artisan test --filter=PageLogicTest`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add hub-php/app/Domain/Maps/Listing.php hub-php/tests/Unit/Maps/PageLogicTest.php
git commit -m "Map listing filters for featured, play type, several tags and upload date"
```

---

### Task 3: The Featured and Newest uploads sections

**Files:**
- Modify: `hub-php/app/Domain/Maps/Listing.php`
- Modify: `hub-php/app/Http/Controllers/Maps/MapsController.php`
- Test: `hub-php/tests/Feature/Maps/MapPagesTest.php`

**Interfaces:**
- Consumes: `Listing::isLanding`, `Lookup::listingRule`, `Lookup::applyListingRule`, `Listing::pictures`.
- Produces: `Listing::SECTION_SIZE = 12` and `Listing::section(string $which): array{maps: list<array<string, mixed>>, pictures: array<string, array<string, mixed>>}`, with `$which` either `'featured'` or `'uploaded'`. It is cached under `'maps:section:'.$which` with tags `[HubCache::MAPS, HubCache::ASSETS]` for `HubCache::WEEKS`. Featured filters `featured_at not null` and orders `featured_at desc, map_name asc`. Uploaded filters `uploaded_at not null` and orders `uploaded_at desc, map_name asc`. Both use `limit(12)` and `SUMMARY_COLUMNS`. A failed licence read or query returns `['maps' => [], 'pictures' => []]`.
- The controller passes `sections` to the view: `['featured' => ..., 'uploaded' => ...]` when `isLanding`, otherwise `[]`.

- [ ] **Step 1: Write the failing feature tests** in `MapPagesTest.php`. The fake routes by path only, so the tests tell queries apart by query string. Change the fake so `$this->answers` may hold a closure `fn (string $query) => array|null` and call it with the decoded query string.
  - `test_the_first_page_shows_the_sections`: `map_browse` answers one featured row for queries containing `featured_at=not.is.null`, one row with slug `fresh` for queries containing `uploaded_at=not.is.null`, and one row for the rest. `GET /maps` shows `Featured`, `View all featured`, `href="/maps?featured=1"`, `Newest uploads`, `View all new uploads`, `href="/maps?sort=uploaded"` and `href="/map/fresh"`.
  - `test_sections_only_show_on_the_unfiltered_first_page`: `GET /maps?page=2`, `/maps?sort=size` and `/maps?featured=1` do not show `View all featured`.
  - `test_an_empty_or_failed_section_is_left_out`: the uploaded query answers `[]` and the page has no `Newest uploads` but still shows `View all featured`. Then the uploaded query answers `null` (503), and the page is still 200 and still shows the grid's card.

- [ ] **Step 2: Run and see them fail.** Run `cd hub-php && php artisan test --filter=MapPagesTest`. Expected: the three new tests fail.

- [ ] **Step 3: Implement `Listing::section` and the controller change.** Put a minimal temporary loop in `maps/index.blade.php` that renders section headings, links and cards with `x-maps.card`, so the tests pass. Task 4 replaces it.

- [ ] **Step 4: Run and see them pass.** Run `cd hub-php && php artisan test --filter=MapPagesTest`. Expected: PASS, including the existing listing tests.

- [ ] **Step 5: Commit**

```bash
git add hub-php/app/Domain/Maps/Listing.php hub-php/app/Http/Controllers/Maps/MapsController.php hub-php/resources/views/maps/index.blade.php hub-php/tests/Feature/Maps/MapPagesTest.php
git commit -m "Featured and newest upload rows on the first maps page"
```

---

### Task 4: Filter bar, grid, section rows and card

**Files:**
- Create: `hub-php/resources/views/components/maps/filter-bar.blade.php`
- Create: `hub-php/resources/views/components/maps/section.blade.php`
- Modify: `hub-php/resources/views/maps/index.blade.php`
- Modify: `hub-php/resources/views/components/maps/card.blade.php`
- Modify: `hub-php/resources/css/app.css`
- Modify: `hub-php/resources/js/maps/listing.js`
- Test: `hub-php/tests/Feature/Maps/MapPagesTest.php`

**Interfaces:**
- Consumes: `Listing::chips`, `Listing::withTag`, `Listing::SORTS`, `Listing::SIZES`, `Listing::isFiltered`, and the controller's `sections`.
- Produces: `<x-maps.filter-bar :filters="$filters" />` and `<x-maps.section :title="..." :more-href="..." :more-label="..." :maps="..." :pictures="..." :filters="$filters" />`. A section renders nothing when `maps` is empty.

Layout decisions:

- **Bar.** A `flex flex-wrap items-center gap-2` row. The seven chips are `<a>` elements with `min-h-9`. Off chips use the existing neutral chip style. On chips use an amber border and amber text, with `aria-current="true"`. After them, one GET `<form action="/maps" data-busy-form>` holds:
  - the search `<input type="search" name="q" placeholder="Search maps..." aria-label="Search maps" data-search-shortcut>`
  - a sort `<select name="sort" aria-label="Sort by" data-submit-on-change>` labelled Name, Longer edge largest first, Players most first, Recently added, Upload date
  - `<details>` with `<summary>More filters</summary>`, holding Made by, Tag, Size and Players at least (the existing controls and labels) and an Apply submit button. It is `open` when `author`, `size`, `players` or a non-chip tag is set.
  - hidden inputs for each `play`, each chip tag, and `featured`, so searching keeps the active chips
  
  The free-text Tag box shows the first tag that is not a chip tag, and submits as one more `tag` value. A Clear link to `/maps` shows when `isFiltered`.
- **Grid.** `grid gap-4 grid-cols-[repeat(auto-fill,minmax(200px,1fr))]` for the listing and the sections.
- **Section row.** `<section aria-labelledby>` with an `h2`, then the "View all" link, then the same grid inside a wrapper with `@container` and the class `map-row`. To show one row and keep hidden cards out of the tab order, add these rules to `app.css`. A container of width W fits `floor((W + 16) / 216)` columns. For k = 1 to 11, emit `@container (max-width: <(k+1)*216 - 16 - 0.02>px) { .map-row > ul > li:nth-child(n+<k+1>) { display: none; } }`. The narrowest matching query wins because every rule hides a superset. Write the 11 rules out literally, with a one-line comment giving the formula.
- **Card.** Square art frame (`aspect-square w-full bg-black rounded-md overflow-hidden flex items-center justify-center`), with the minimap `<img>` at the map's own `aspect-ratio`, `max-h-full max-w-full`. The placeholder uses the same frame. The Featured badge stays. Title `h2` becomes `h3` inside sections and stays `h2` in the grid, so add a `headingLevel` prop with default 2. The canonical-name line stays. One meta `<p>` holds size, starts (`Labels::players`) and authors joined by ` · `, with author links kept. Tag chips sit in `flex flex-nowrap overflow-hidden`, using `Listing::withTag` hrefs (or `size` for size bands, as today). Padding drops to `p-3`, and `$maxWidth` is removed.
- **Eager images.** The first 12 grid cards and all section cards use `eager`.
- **JS.** In `listing.js`, any `select[data-submit-on-change]` calls `form.requestSubmit()` on `change`.

- [ ] **Step 1: Update the failing feature tests.**
  - In `test_the_listing_shows_a_card_per_map_and_a_pager`: the selected option is `<option value="players" selected>Players, most first</option>` with that label, or the new one if you change it. The author href becomes `href="/maps?author=beherith&amp;size=medium&amp;sort=players"`. `Featured` still shows.
  - Add `test_the_filter_bar`: `GET /maps?play=1v1&tag=tidal` shows the `1v1` and `Tidal` chip anchors with `aria-current="true"` and the `Team` chip anchor without it (assert each anchor's full markup, since the pager also uses `aria-current`), `<input type="hidden" name="play" value="1v1">`, `<input type="hidden" name="tag" value="tidal">`, `data-submit-on-change`, and `<details` without `open`. `GET /maps?author=x` shows `<details open`.
  - Add `test_section_rows_hide_overflow_with_css`: the section markup contains `class="map-row`.
  - Add `test_uploaded_sort_with_nothing_uploaded_says_no_match`: `GET /maps?sort=uploaded` shows `No map matches that.`.

- [ ] **Step 2: Run and see them fail.** Run `cd hub-php && php artisan test --filter=MapPagesTest`. Expected: the new tests fail.

- [ ] **Step 3: Build the components, CSS, card and JS** as above. Remove the temporary loop from Task 3.

- [ ] **Step 4: Run the whole suite and build.** Run `cd hub-php && php artisan test`, then `npm run build`. Expected: all tests pass, and the build exits 0.

- [ ] **Step 5: Commit**

```bash
git add hub-php/resources/views/components/maps/filter-bar.blade.php hub-php/resources/views/components/maps/section.blade.php hub-php/resources/views/maps/index.blade.php hub-php/resources/views/components/maps/card.blade.php hub-php/resources/css/app.css hub-php/resources/js/maps/listing.js hub-php/tests/Feature/Maps/MapPagesTest.php
git commit -m "Coilbox style filter bar and a maps grid that adds columns"
```

---

### Task 5: Visual check (lead only)

The lead does this, because it needs the running PHP app. The app reads production, where the new view columns do not exist until the migration is pushed. Before the push, the default listing, the chips' links, the bar and the Featured row work. The Newest uploads row is left out because its query fails, which is the failure path from Review Focus item 4.

- [ ] **Step 1:** Confirm `http://localhost:8100/maps` is served by the PHP app. Use `agent-browser` to screenshot at widths 390, 1440 and 2560. Expected: the column count grows with width (at 2560 the content box is 2512px after the page's 24px side padding, so `floor((2512 + 16) / 216)` = 11 columns). The Featured row is one row at each width. Tabbing through the Featured row never lands on a hidden card.
- [ ] **Step 2:** Screenshot `/maps?author=<a real author key>` and confirm "More filters" is open. Then toggle 1v1 and confirm the chip turns amber and the URL gains `play=1v1`. The 1v1 query will fail on production before the push and show the error message. That is expected here, and is confirmed after the push.
- [ ] **Step 3:** After the owner approves shipping: run `supabase migration list --linked`, then `supabase db push --linked`, then `migration list` again. Then recheck 1v1, Team and `sort=uploaded` on the live page.
