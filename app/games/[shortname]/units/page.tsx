import type { Metadata } from "next";
import Link from "next/link";
import { connection } from "next/server";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { archives } from "@/components/art/drawings";
import { UnitCard } from "@/components/UnitCard";
import { PAGE_GAP, pageNumbers } from "@/lib/gallery/query";
import { unitGridCached } from "@/lib/games/cached";
import { parseUnitGridFilters, UNIT_PAGE_SIZE } from "@/lib/games/units";

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
  const { shortname } = await params;
  return {
    title: `${shortname} units - Coilbox Hub`,
    description: `Every unit ${shortname} ships, with its stats and build tree.`,
  };
}

const BACKDROP_STRENGTH = 0.05;

const CONTROL =
  "w-full rounded-md border border-neutral-800 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-500 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400";

export default async function Units({
  params,
  searchParams,
}: {
  params: Promise<{ shortname: string }>;
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  await connection();
  const { shortname } = await params;
  const filters = parseUnitGridFilters(await searchParams);
  const { units, count, error } = await unitGridCached(shortname, filters);
  const lastPage = Math.max(1, Math.ceil(count / UNIT_PAGE_SIZE));

  const pageHref = (page: number) => {
    const query = new URLSearchParams();
    if (filters.q) query.set("q", filters.q);
    if (filters.retired) query.set("retired", "1");
    if (page > 1) query.set("page", String(page));
    const suffix = query.toString();
    return `/games/${shortname}/units${suffix ? `?${suffix}` : ""}`;
  };

  return (
    <main className="relative flex-1">
      <ArtBackdrop drawing={archives} strength={BACKDROP_STRENGTH} />
      <div className="relative z-10 mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <nav className="text-sm text-neutral-500" aria-label="Breadcrumb">
          <Link href={`/games/${shortname}`} className="underline-offset-4 hover:underline active:underline">
            {shortname}
          </Link>
          <span aria-hidden> / </span>
          <span className="text-neutral-300">Units</span>
        </nav>

        <div className="flex flex-col gap-2">
          <h1 className="text-3xl font-semibold tracking-tight">Units</h1>
          <p className="text-neutral-400">
            Every unit this game ships, as the hub holds it.
          </p>
        </div>

        {/* A GET to this same route, so submitting it produces the URL the
            filters describe and the back button works. */}
        <form action={`/games/${shortname}/units`} className="flex flex-wrap items-end gap-3 border-b border-neutral-900 pb-6">
          <div className="flex min-w-48 flex-1 flex-col gap-1.5">
            <label htmlFor="units-q" className="text-xs uppercase tracking-wide text-neutral-400">
              Search
            </label>
            <input id="units-q" type="search" name="q" defaultValue={filters.q ?? ""} className={CONTROL} />
          </div>
          <label className="flex items-center gap-2 pb-2 text-sm text-neutral-400">
            <input type="checkbox" name="retired" value="1" defaultChecked={filters.retired} className="size-4" />
            Show retired units
          </label>
          <button
            type="submit"
            className="rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
          >
            Filter
          </button>
        </form>

        {error ? (
          <p className="text-sm text-red-400">
            The catalog could not be read just now. Try again in a moment.
          </p>
        ) : units.length === 0 ? (
          <div className="rounded-md border border-neutral-800 bg-neutral-950 p-8 text-center">
            <p className="text-sm text-neutral-400">
              {count > 0
                ? "That page is past the last unit."
                : filters.q || filters.retired
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
        ) : (
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
              <nav aria-label="Pages" className="flex flex-wrap items-center justify-center gap-1.5 text-sm text-neutral-500">
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
        )}
      </div>
    </main>
  );
}
