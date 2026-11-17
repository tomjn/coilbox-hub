# Maps page redesign

Date: 2026-11-17. App: `hub-php/` (the live hub). The Next.js app in the repository root is not changed.

## Why

The `/maps` page gives every filter the same weight, has no one-click filters, and stops at five columns, so cards stretch on wide screens. Coilbox's own Maps page has a single filter bar with quick filter chips and a dense grid that keeps adding columns. The hub now accepts map uploads, so the first page should also show featured maps and the newest uploads.

## Outcome

- One filter bar modelled on Coilbox: quick filter chips, search, sort, and a "More filters" panel for the rest.
- A grid that adds columns as the screen widens, with Coilbox-density cards.
- On the unfiltered first page, a Featured row and a Newest uploads row above the bar, each with a link to a pre-filtered listing.
- A sort by upload date that lists only maps uploaded through the hub mirror system.

## 1. Data

One new migration replaces `public.map_browse`. The body is carried forward unchanged from `20260918150000_map_featured.sql`, with two columns appended at the end of the select list (`create or replace view` refuses columns inserted between existing ones). Grants are repeated, as before.

- `uploaded_at timestamptz`: `max(archive.listed_at)` over `public.archive` rows where `kind = 'map'`, `state = 'listed'` and `internal_name = map.map_name`. Null for a map never uploaded to the hub. The join is the one `archive_moderation_row` already uses. `archive_kind_name_listed_idx` covers it.
- `play_types text[]`: contains `1v1` when the map has exactly 2 start positions or carries the `1v1` tag, and `team` when it has 4 or more start positions. Empty otherwise. These are Coilbox's rules (`coilbox/src/content/libraryMaps.ts`, `mapPlayTypes`), so a chip matches the same maps in the app and on the hub.

`public.archive` grants nothing to `anon`, `authenticated` or `service_role` (`20261117120000_hub_mirrors.sql`, `revoke all`), and the view is `security_invoker`. So `uploaded_at` comes from a new `security definer` SQL function, `public.map_uploaded_at(map_name text) returns timestamptz`, with `set search_path = ''`. It returns only that one timestamp, so it exposes nothing else about uploads. Execute is granted to `anon`, `authenticated` and `service_role`.

The migration is applied to production with `supabase db push --linked` once the work is approved to ship, per the repository instructions.

## 2. Listing parameters (`App\Domain\Maps\Listing`)

| Param | Meaning |
|---|---|
| `featured=1` | Only maps with `featured_at` set |
| `play` (repeatable: `1v1`, `team`) | Map's `play_types` overlaps the chosen values. Two chips on means either matches |
| `tag` (now repeatable) | Map has every chosen tag. The terrain chips set `water map`, `tidal`, `windy` |
| `sort=uploaded` | Order by `uploaded_at` newest first, and drop maps where it is null |

Existing params (`q`, `author`, `size`, `players`, `sort`, `page`) keep their meaning. `tag=small` from a card chip still becomes the size filter. Unknown values are dropped, as today, so old links keep working.

Ordering changes:

- Featured maps are no longer pinned above the reader's chosen order. The Featured section and chip replace the pin.
- Every order still ends in `map_name`, so paging stays stable.

`filterHref` and `isFiltered` learn the new params. `featured` and `play` count as narrowing. `sort=uploaded` counts as narrowing too, because it hides most of the catalog.

`PAGE_SIZE` becomes 60. 60 divides into whole rows at 2, 3, 4, 5, 6, 10 and 12 columns. It was picked for that, not measured.

## 3. Filter bar (`resources/views/maps/index.blade.php`)

The current six-field form is replaced by one bar that wraps on narrow screens:

```
[Featured] [New uploads] [1v1] [Team] [Water] [Tidal] [Windy]  [Search maps...]  [Sort: Name v]  [More filters]
```

- Chips are links built with `filterHref`, toggling their own filter and keeping the rest. They work with scripting off. The active state follows Coilbox, with an amber border and amber text. Active chips also carry `aria-current="true"` so screen readers announce which are on.
- "New uploads" is `sort=uploaded`. Turning it off returns to the default Name sort. The sort dropdown also offers "Upload date".
- Search and sort sit in one GET form, which carries the active chip params as hidden inputs so a search does not drop them. With scripting on, changing the sort submits the form (added to `resources/js/maps/listing.js`). The `/` shortcut to the search box stays.
- "More filters" is a native `<details>` element holding Made by, Tag (free text), Size, Players at least and an Apply button. It is open on load when any of those four is set. The Apply button is also how a reader without scripting submits a sort change.
- A Clear link to `/maps` shows whenever `isFiltered` is true.

The page heading and intro sentence stay.

## 4. First page sections

Shown only on `/maps` with no filter params and page 1, above the filter bar, in this order:

1. Featured: the most recently featured maps first. Link: "View all featured" to `/maps?featured=1`.
2. Newest uploads: by `uploaded_at` newest first. Link: "View all new uploads" to `/maps?sort=uploaded`.

Each section fetches 12 maps, through the same licence gate and picture lookup as the listing, and shows one row. The row uses the grid in section 5 with `grid-auto-rows: 0` and hidden overflow after the first row, so a narrow screen shows fewer cards and a wide one more. A screen wide enough for more than 12 columns shows a short row. A section with no maps is not rendered.

Both sections are cached with the `maps` and `assets` tags, like the listing. Mirror reports and removals already clear the `maps` tag (`App\Domain\Mirrors\Reports`, `App\Domain\Mirrors\Removal`), and featuring a map already clears it (`Moderation\MapsController`), so no new invalidation is needed.

If either section's read fails, that section is left out and the grid still renders. The grid keeps its existing error message.

## 5. Grid and card

- Grid: `grid-template-columns: repeat(auto-fill, minmax(200px, 1fr))` with the current gap. This replaces the `sm:` to `xl:` column classes. Used by the listing and both sections.
- Card (`resources/views/components/maps/card.blade.php`), following the Coilbox layout:
  - A square art frame across the full card width. The minimap is centred in it at the map's own proportions on a dark background. The placeholder drawing fills the same frame.
  - The Featured badge stays in the top left of the art.
  - Title. The canonical map name line stays when the archive gave a friendlier title.
  - One meta line: size, starts, authors, separated by " · ". Author names stay links to the author filter.
  - Tags as small chips, kept to one line with overflow hidden. They stay because they are how readers find a tag.
- The first 12 cards on the page load eagerly. The rest stay `loading="lazy"`. Today the first 10 load eagerly.

## Testing

- Unit tests for `Listing::parseFilters`, `filterHref` and `isFiltered` covering `featured`, `play`, repeatable `tag`, `sort=uploaded`, and dropping unknown values.
- Feature tests for `/maps`: the sections appear only unfiltered on page 1, a section with no maps is not rendered, chips toggle the right params, `sort=uploaded` lists only uploaded maps.
- A SQL check against local Supabase that `play_types` and `uploaded_at` come out right for a 2-start map, a 4-start map, a `1v1`-tagged 6-start map, and a map with a listed and a removed archive.
- A screenshot at a narrow width, at 1440px and at 2560px wide, to confirm the column count grows and the section rows stay one row.

## Out of scope

- An FFA chip. Start count cannot tell an FFA map from a team map, and no `ffa` tag convention exists.
- Changes to the Next.js reference app.
- Changes to the map detail page or the moderation page.
