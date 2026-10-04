import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { GameBreadcrumb } from "@/components/Breadcrumb";
import { archives } from "@/components/art/drawings";
import { FactionToggles, type FactionToggleOption } from "@/components/FactionToggles";
import { Skeleton } from "@/components/Skeleton";
import { UnitCard } from "@/components/UnitCard";
import { PAGE_GAP, pageNumbers } from "@/lib/gallery/query";
import { gamePageCached, retiredUnitsHeldCached, unitGridCached } from "@/lib/games/cached";
import { gameTitle } from "@/lib/games/labels";
import { parseUnitGridFilters, UNIT_PAGE_SIZE } from "@/lib/games/units";
import { Button } from "@/components/Button";

/**
 * Every unit a game ships (#227).
 *
 * The maps listing's shape: filters as query parameters, a form that submits to
 * this same route, and paging as ordinary links. Nothing here needs a bundle,
 * because a filtered listing is a GET request and the URL is the state.
 *
 * Retired units are hidden by default. A balance patch that removed a unit did
 * not erase it - an old replay still names it - so `?retired=1` is how it is
 * found again, and the toggle says what it shows rather than hiding behind a
 * checkbox nobody understands.
 */

export async function generateMetadata({
  params,
}: {
  params: Promise<{ shortname: string }>;
}): Promise<Metadata> {
  const game = await gamePageCached((await params).shortname);
  if (!game) return { title: "Not found" };
  const name = gameTitle(game);
  return {
    title: `${name} units`,
    description: `Every unit ${name} ships, with its stats and build tree.`,
  };
}

const BACKDROP_STRENGTH = 0.05;

const CONTROL =
  "w-full rounded-md border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-400 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400";

type Params = Promise<{ shortname: string }>;
type SearchParams = Promise<Record<string, string | string[] | undefined>>;

/** The faction toggles and the search form. They read the query string to show
 *  what is chosen, so they sit behind a boundary of their own and not the list's:
 *  the list is what a click on either of them has to wait for. */
async function Filters({ params, searchParams }: { params: Params; searchParams: SearchParams }) {
  const { shortname } = await params;
  const game = await gamePageCached(shortname);
  if (!game) return null;
  const filters = parseUnitGridFilters(await searchParams);
  // The retired toggle only exists for a game that has retired something, and
  // whether it has is not a function of the filters.
  const retiredHeld = await retiredUnitsHeldCached(shortname);

  // Faction as toggles rather than a dropdown (#269). Each option is a link
  // carrying the other filters, and choosing a side restarts paging.
  const factionHref = (key: string | null) => {
    const query = new URLSearchParams();
    if (filters.q) query.set("q", filters.q);
    if (filters.retired) query.set("retired", "1");
    if (key) query.set("faction", key);
    const suffix = query.toString();
    return `/games/${shortname}/units${suffix ? `?${suffix}` : ""}`;
  };
  const factionOptions: FactionToggleOption[] = [
    { key: "", label: "All factions", href: factionHref(null), active: !filters.faction },
    ...game.factions.map((faction) => ({
      key: faction.key,
      label: faction.name,
      href: factionHref(faction.key),
      active: filters.faction === faction.key,
    })),
  ];

  return (
    <>
      {game.factions.length > 1 ? <FactionToggles options={factionOptions} /> : null}
      <form action={`/games/${shortname}/units`} className="flex flex-wrap items-end gap-3">
        <div className="flex min-w-48 flex-1 flex-col gap-1.5">
          <label htmlFor="units-q" className="text-xs uppercase tracking-wide text-neutral-400">
            Search
          </label>
          <input id="units-q" type="search" name="q" defaultValue={filters.q ?? ""} className={CONTROL} />
        </div>
        {retiredHeld ? (
          <label className="flex items-center gap-2 pb-2 text-sm text-neutral-400">
            <input type="checkbox" name="retired" value="1" defaultChecked={filters.retired} className="size-4" />
            Show retired units
          </label>
        ) : null}
        {filters.faction ? <input type="hidden" name="faction" value={filters.faction} /> : null}
        <Button
          type="submit"
        >
          Filter
        </Button>
      </form>
    </>
  );
}

async function UnitList({ params, searchParams }: { params: Params; searchParams: SearchParams }) {
  const { shortname } = await params;
  // A hidden game is invisible at every level, so the grid answers not-found
  // rather than rendering an empty shelf that reads as "nobody has reported
  // units yet".
  const game = await gamePageCached(shortname);
  if (!game) notFound();
  const filters = parseUnitGridFilters(await searchParams);
  const { units, count, error } = await unitGridCached(shortname, filters);
  const lastPage = Math.max(1, Math.ceil(count / UNIT_PAGE_SIZE));

  const pageHref = (page: number) => {
    const query = new URLSearchParams();
    if (filters.q) query.set("q", filters.q);
    if (filters.retired) query.set("retired", "1");
    if (filters.faction) query.set("faction", filters.faction);
    if (page > 1) query.set("page", String(page));
    const suffix = query.toString();
    return `/games/${shortname}/units${suffix ? `?${suffix}` : ""}`;
  };

  if (error) {
    return (
      <p className="text-sm text-red-400">
        The catalog could not be read just now. Try again in a moment.
      </p>
    );
  }

  if (units.length === 0) {
    return (
      <div className="rounded-md border border-neutral-800 bg-card p-8 text-center">
        <p className="text-sm text-neutral-400">
          {count > 0
            ? "That page is past the last unit."
            : filters.q || filters.retired || filters.faction
              ? "No unit matches that."
              : "Nobody has reported this game's units yet."}
        </p>
        <Link
          href={`/games/${shortname}/units`}
          className="mt-3 inline-block text-sm text-neutral-300 underline-offset-4 hover:underline active:underline"
        >
          Back to the first page
        </Link>
      </div>
    );
  }

  return (
    <>
      <ul className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8">
        {units.map((entry, index) => (
          <UnitCard
            key={entry.unit.unit_name}
            game={shortname}
            unit={entry.unit}
            picture={entry.picture}
            eager={index < 16}
          />
        ))}
      </ul>

      {lastPage > 1 ? (
        <nav aria-label="Pages" className="flex flex-wrap items-center justify-center gap-1.5 text-sm text-neutral-400">
          {pageNumbers(filters.page, lastPage).map((step, index) =>
            step === PAGE_GAP ? (
              <span key={`gap-${index}`} aria-hidden className="px-1">
                &hellip;
              </span>
            ) : step === filters.page ? (
              <span key={step} aria-current="page" className="px-2 py-1 text-neutral-200">
                {step}
              </span>
            ) : (
              <Link key={step} href={pageHref(step)} className="px-2 py-1 underline-offset-4 hover:underline active:underline">
                {step}
              </Link>
            ),
          )}
        </nav>
      ) : null}
    </>
  );
}

/** The route. The backdrop and the heading never change, so they are drawn
 *  at once. Everything that reads `params` or the query string sits behind a
 *  boundary, and the list has one apart from the filters so that choosing a
 *  filter or a page swaps the list for stand-ins and leaves the rest alone. */
export default function Units({ params, searchParams }: { params: Params; searchParams: SearchParams }) {
  return (
    <main id="main-content" tabIndex={-1} className="relative flex-1">
      <ArtBackdrop drawing={archives} strength={BACKDROP_STRENGTH} />
      <div className="relative z-10 mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <Suspense fallback={<Skeleton className="h-5 w-32" />}>
          <GameBreadcrumb params={params} current="Units" />
        </Suspense>

        <div className="flex flex-col gap-2">
          <h1 className="text-3xl font-semibold tracking-tight">Units</h1>
          <p className="text-neutral-400">
            Every unit this game ships, as the hub holds it.
          </p>
        </div>

        {/* A GET to this same route, so submitting it produces the URL the
            filters describe and the back button works. The faction choice is
            not a form field: it is a set of links above, since a side is what
            you are looking at rather than something to filter by (#269). */}
        <div className="flex flex-col gap-4 border-b border-neutral-900 pb-6">
          <Suspense fallback={<Skeleton className="h-16 w-full" />}>
            <Filters params={params} searchParams={searchParams} />
          </Suspense>
        </div>

        <Suspense
          fallback={
            <ul className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8" aria-busy="true">
              {Array.from({ length: 16 }, (_, i) => (
                <li key={i}>
                  <Skeleton className="aspect-square" />
                </li>
              ))}
            </ul>
          }
        >
          <UnitList params={params} searchParams={searchParams} />
        </Suspense>
      </div>
    </main>
  );
}
