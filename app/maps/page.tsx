import type { Metadata } from "next";
import Link from "next/link";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { skirmish } from "@/components/art/drawings";
import { MapCard } from "@/components/MapCard";
import { fetchPage, PAGE_GAP, pageNumbers } from "@/lib/gallery/query";
import {
  applyFilters,
  applySort,
  type Filters,
  filterHref,
  isFiltered,
  MAP_PAGE_SIZE,
  MAP_SIZES,
  MAP_SORTS,
  MAP_SUMMARY_COLUMNS,
  type MapSummary,
  mapPictures,
  parseFilters,
  resolveAuthorKey,
} from "@/lib/maps/query";
import { createClient } from "@/lib/supabase/server";

/**
 * Every map the hub knows about (issue #189).
 *
 * One route with the filters as query parameters, rather than a route per
 * author and a route per tag. "All maps by beherith" is a link somebody pastes
 * into lobby chat and a query string is already that, so there is one page to
 * build, one query to test, and every filter combines with every other for free.
 *
 * ## It works with scripting off
 *
 * The filters are a form that submits to this same route, the sort is a select
 * in it, and paging is two ordinary links. Nothing here is a control that only
 * starts working once a bundle has loaded, and every input has a visible label
 * rather than a placeholder standing in for one.
 *
 * The URL is the whole of the state. That is what makes the page linkable, and
 * it is also what makes it work without a script: a form with no handler is a
 * GET to its action, which is exactly the request a filtered listing is.
 *
 * ## Read with the visitor's own client
 *
 * Unlike a map's own page, nothing here needs the secret key. `public.map` and
 * everything the listing view derives from it carry a read all policy, so the
 * whole listing comes back through row level security. The licence gate is the
 * one thing that needs `service_role`, and it decides whether a map has a page
 * rather than whether it is in the catalog.
 */

export const metadata: Metadata = {
  title: "Maps - Coilbox Hub",
  description: "Browse the maps the hub holds facts about, by size, tag, author or name.",
};

/** The same reading `app/map/[slug]/page.tsx` gives for this drawing: a page of
 *  minimaps is a page of terrain, and a backdrop competing with it makes the
 *  terrain harder to read. */
const BACKDROP_STRENGTH = 0.05;

/** What the reader is looking at, so the label under the pager says how much
 *  there is rather than only which page this is. */
function pageLabel(page: number, lastPage: number, total: number): string {
  const maps = total === 1 ? "1 map" : `${total} maps`;
  return `Page ${page} of ${lastPage}, ${maps}`;
}

export default async function Maps({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const filters = parseFilters(await searchParams);
  const supabase = await createClient();

  // The one thing that cannot be worked out here. A reader arrives with a
  // spelling and the catalog files a key, and the rules turning one into the
  // other live in the database so there is only ever one copy of them.
  // `lib/maps/query.ts` sets out what a second copy would cost.
  const authorKey = await resolveAuthorKey(supabase, filters.author);

  const listing = () =>
    applySort(
      applyFilters(
        supabase.from("map_browse").select(MAP_SUMMARY_COLUMNS, { count: "exact" }),
        filters,
        authorKey,
      ),
      filters.sort,
    );

  const { data, count, error } = await fetchPage(
    () => listing().range((filters.page - 1) * MAP_PAGE_SIZE, filters.page * MAP_PAGE_SIZE - 1),
    async () => {
      const { count, error } = await listing().range(0, 0);
      return { count, error };
    },
  );
  const maps = data as unknown as MapSummary[];
  const lastPage = Math.max(1, Math.ceil(count / MAP_PAGE_SIZE));

  // One batched lookup for the whole page rather than one per card.
  const pictures = await mapPictures(supabase, maps);

  return (
    <main className="relative flex-1">
      <ArtBackdrop drawing={skirmish} strength={BACKDROP_STRENGTH} />
      <div className="relative z-10 mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <div className="flex flex-col gap-2">
          <h1 className="text-3xl font-semibold tracking-tight">Maps</h1>
          <p className="text-neutral-400">
            What the hub knows about the maps people play on, from the archives
            themselves.
          </p>
        </div>

        {/* A GET to this same route, so submitting it produces the URL the
            filters describe and the back button works. The page is deliberately
            not in the form: changing a filter and staying on page 7 is almost
            never where anybody wanted to be. */}
        <form
          action="/maps"
          className="grid gap-4 border-b border-neutral-900 pb-6 sm:grid-cols-2 lg:grid-cols-3"
        >
          <Field label="Search" htmlFor="maps-q">
            <input
              id="maps-q"
              type="search"
              name="q"
              defaultValue={filters.q ?? ""}
              className={CONTROL}
            />
          </Field>

          <Field label="Made by" htmlFor="maps-author">
            <input
              id="maps-author"
              type="text"
              name="author"
              defaultValue={filters.author ?? ""}
              className={CONTROL}
            />
          </Field>

          {/* A text box rather than a list to pick from. The derived tags are
              named in 20260818120000_map_listing.sql and the curated ones are
              whatever a maintainer wrote, so a list here would either be a second
              copy of that vocabulary or a scan of the whole catalog on every page
              load. The chips on the cards are how a reader finds a tag, and this
              is how they keep it. */}
          <Field label="Tag" htmlFor="maps-tag">
            <input
              id="maps-tag"
              type="text"
              name="tag"
              defaultValue={filters.tag ?? ""}
              className={CONTROL}
            />
          </Field>

          <Field label="Size" htmlFor="maps-size">
            <select id="maps-size" name="size" defaultValue={filters.size ?? ""} className={CONTROL}>
              <option value="">Any size</option>
              {MAP_SIZES.map((size) => (
                <option key={size} value={size}>
                  {SIZE_LABELS[size]}
                </option>
              ))}
            </select>
          </Field>

          <Field label="Players, at least" htmlFor="maps-players">
            <input
              id="maps-players"
              type="number"
              name="players"
              min={1}
              defaultValue={filters.players ?? ""}
              className={CONTROL}
            />
          </Field>

          <Field label="Sort by" htmlFor="maps-sort">
            <select id="maps-sort" name="sort" defaultValue={filters.sort} className={CONTROL}>
              {MAP_SORTS.map((sort) => (
                <option key={sort} value={sort}>
                  {SORT_LABELS[sort]}
                </option>
              ))}
            </select>
          </Field>

          <div className="flex items-end gap-3">
            <button
              type="submit"
              className="rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 hover:text-white"
            >
              Filter
            </button>
            {isFiltered(filters) ? (
              <Link href="/maps" className="text-sm text-neutral-400 hover:text-neutral-200">
                Clear
              </Link>
            ) : null}
          </div>
        </form>

        {error ? (
          <p className="text-sm text-red-400">
            The catalog could not be read just now. Try again in a moment.
          </p>
        ) : maps.length === 0 ? (
          <Empty filtered={isFiltered(filters)} filters={filters} total={count} />
        ) : (
          <>
            <ul className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
              {maps.map((map, index) => {
                const picture = pictures.get(map.map_name);
                return picture ? (
                  <li key={map.id}>
                    {/* Two rows of four fill a desktop's first screen and more
                        than a phone's, so those load with the page and the rest
                        wait until they are scrolled towards. */}
                    <MapCard map={map} picture={picture} filters={filters} eager={index < 8} />
                  </li>
                ) : null;
              })}
            </ul>

            <Pager
              filters={filters}
              lastPage={lastPage}
              label={pageLabel(filters.page, lastPage, count)}
            />
          </>
        )}
      </div>
    </main>
  );
}

const CONTROL =
  "w-full rounded-md border border-neutral-800 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-500 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400";

/** Every step in the pager is the same box, so a number, a previous and a next
 *  are one row of equal targets rather than two words with digits between them.
 *  36px square at its smallest, which clears the 24px a pointer target has to
 *  be. */
const STEP =
  "flex min-h-9 min-w-9 items-center justify-center rounded-md border border-neutral-800 px-2 transition-colors hover:border-neutral-600 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400";

/**
 * Which page to read next, as numbers rather than as two words.
 *
 * A previous and a next alone say how to step and nothing else. They cannot say
 * where the reader is in a catalog of thousands, cannot get them back to the
 * start, and cannot get them to the end without a click per page. The numbers
 * `pageNumbers` picks answer all three, and the sentence underneath still says
 * how much there is, which no run of digits does.
 *
 * Every step is an ordinary link. The listing works with scripting off and the
 * pager is the last part of it that would have needed a script.
 */
function Pager({
  filters,
  lastPage,
  label,
}: {
  filters: Filters;
  lastPage: number;
  label: string;
}) {
  return (
    <nav aria-label="Pages" className="flex flex-col items-center gap-3 text-sm text-neutral-500">
      <ul className="flex flex-wrap items-center justify-center gap-1.5">
        {filters.page > 1 ? (
          <li>
            <Link href={filterHref(filters, { page: filters.page - 1 })} className={STEP}>
              Previous
            </Link>
          </li>
        ) : null}

        {pageNumbers(filters.page, lastPage).map((step, index) =>
          step === PAGE_GAP ? (
            // The gap is not a control, so it is not in the tab order and is not
            // read out. The numbers either side of it already say a stretch was
            // left out.
            <li key={`gap-${index}`} aria-hidden className="px-1">
              &hellip;
            </li>
          ) : step === filters.page ? (
            <li key={step}>
              <span
                aria-current="page"
                className={`${STEP} border-neutral-600 bg-neutral-900 text-neutral-100`}
              >
                {step}
              </span>
            </li>
          ) : (
            <li key={step}>
              <Link href={filterHref(filters, { page: step })} className={STEP}>
                {step}
              </Link>
            </li>
          ),
        )}

        {filters.page < lastPage ? (
          <li>
            <Link href={filterHref(filters, { page: filters.page + 1 })} className={STEP}>
              Next
            </Link>
          </li>
        ) : null}
      </ul>
      <p>{label}</p>
    </nav>
  );
}

/** What each band means in the words a player uses, since `small` on its own
 *  says nothing about where the line is. The numbers are
 *  20260818120000_map_listing.sql's. */
const SIZE_LABELS: Record<(typeof MAP_SIZES)[number], string> = {
  small: "Small, up to 12 squares",
  medium: "Medium, up to 20 squares",
  large: "Large, over 20 squares",
};

const SORT_LABELS: Record<(typeof MAP_SORTS)[number], string> = {
  name: "Name",
  size: "Longer edge, largest first",
  players: "Players, most first",
  added: "Recently added",
};

/** A visible label above every control, rather than a placeholder standing in
 *  for one. A placeholder disappears the moment somebody types, which is when a
 *  reader most needs to know which box they are in. */
function Field({
  label,
  htmlFor,
  children,
}: {
  label: string;
  htmlFor: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={htmlFor} className="text-xs uppercase tracking-wide text-neutral-400">
        {label}
      </label>
      {children}
    </div>
  );
}

/**
 * Why there is nothing here, which is three different things.
 *
 * The page ran off the end is the one worth telling apart. Paging one step past
 * the last map is routine, and the last page somebody saw a moment ago can be
 * gone by the time they click next, so `fetchPage` turns it into an empty page
 * against the real total rather than a failure. Reading that as "no map matches
 * that" would tell somebody their filter was wrong when it matched plenty.
 */
function Empty({
  filtered,
  filters,
  total,
}: {
  filtered: boolean;
  filters: Filters;
  total: number;
}) {
  if (total > 0) {
    return (
      <div className="rounded-md border border-neutral-800 bg-neutral-950 p-8 text-center">
        <p className="text-sm text-neutral-400">That page is past the last map.</p>
        <Link
          href={filterHref(filters, { page: 1 })}
          className="mt-3 inline-block text-sm text-neutral-300 underline-offset-4 hover:underline"
        >
          Back to the first page
        </Link>
      </div>
    );
  }

  return (
    <div className="rounded-md border border-neutral-800 bg-neutral-950 p-8 text-center">
      <p className="text-sm text-neutral-400">
        {filtered ? "No map matches that." : "The hub holds facts about no maps yet."}
      </p>
      {filtered ? (
        <Link
          href="/maps"
          className="mt-3 inline-block text-sm text-neutral-300 underline-offset-4 hover:underline"
        >
          Clear the filters
        </Link>
      ) : null}
    </div>
  );
}
