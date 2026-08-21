import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { connection } from "next/server";
import { AssetPlaceholder } from "@/components/AssetPlaceholder";
import { StatTable } from "@/components/StatTable";
import { unitPageCached } from "@/lib/games/cached";

/**
 * One unit (#227).
 *
 * The top down render large, the stats as a table, what it builds, and which
 * release the facts came from. An author snippet will sit under the description
 * once ownership exists to write one (#229); the space is deliberately not
 * drawn now, because an empty box labelled "the author says nothing yet" would
 * be a promise about a feature rather than a fact about a unit.
 *
 * ## Versions
 *
 * Current facts by default. `?v=<release>` reads that release's revision
 * instead, and the page says so in the same breath as the numbers, because a
 * stat table without its version is a claim nobody can check. A release with no
 * revision for this unit still renders: the page shows current facts and says
 * the record is missing, since a unit existing today but not in some old
 * release is an ordinary answer.
 */

export async function generateMetadata({
  params,
}: {
  params: Promise<{ shortname: string; unit: string }>;
}): Promise<Metadata> {
  const { shortname, unit } = await params;
  return {
    title: `${unit} - ${shortname} - Coilbox Hub`,
    description: `Stats, build options and renders for ${unit}, as ${shortname} ships it.`,
  };
}

export default async function Unit({
  params,
  searchParams,
}: {
  params: Promise<{ shortname: string; unit: string }>;
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  await connection();
  const { shortname, unit } = await params;
  const raw = (await searchParams).v;
  const v = Array.isArray(raw) ? raw[0] : raw;

  const loaded = await unitPageCached(shortname, unit, v);
  if (!loaded) notFound();
  const { page, render } = loaded;
  const label = page.full_name ?? page.unit_name;

  const versionQuery = (version?: string) =>
    version ? `?v=${encodeURIComponent(version)}` : "";

  return (
    <main className="relative flex-1">
      <div className="mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <nav className="text-sm text-neutral-500" aria-label="Breadcrumb">
          <Link href="/games" className="underline-offset-4 hover:underline active:underline">
            Games
          </Link>
          <span aria-hidden> / </span>
          <Link href={`/games/${shortname}`} className="underline-offset-4 hover:underline active:underline">
            {shortname}
          </Link>
          <span aria-hidden> / </span>
          <Link href={`/games/${shortname}/units`} className="underline-offset-4 hover:underline active:underline">
            Units
          </Link>
          <span aria-hidden> / </span>
          <span className="text-neutral-300">{label}</span>
        </nav>

        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:gap-8">
          <div className="flex w-48 shrink-0 items-center justify-center rounded-md border border-neutral-900 bg-black p-4">
            {render.from === "placeholder" ? (
              <AssetPlaceholder of={render} className="w-full" />
            ) : (
              // eslint-disable-next-line @next/next/no-img-element -- the hub serves no picture through next/image; see next.config.ts
              <img
                src={render.url}
                alt={`Top down render of ${label}`}
                width={render.width}
                height={render.height}
                decoding="async"
                className="h-auto w-full object-contain"
              />
            )}
          </div>

          <div className="flex flex-col gap-2">
            <h1 className="text-3xl font-semibold tracking-tight">{label}</h1>
            <p className="font-mono text-sm text-neutral-500">{page.unit_name}</p>
            <div className="flex flex-wrap items-center gap-2 text-sm text-neutral-400">
              {page.faction_name ? (
                <span className="rounded bg-neutral-900 px-2 py-1">{page.faction_name}</span>
              ) : null}
              {page.removed_at ? (
                <span className="rounded bg-neutral-900 px-2 py-1 text-neutral-500">
                  Retired
                </span>
              ) : null}
              {page.shown_version ? (
                <span className="rounded bg-neutral-900 px-2 py-1">
                  Showing release {page.shown_version}
                </span>
              ) : null}
            </div>
            {page.source_version && !page.shown_version ? (
              <p className="text-sm text-neutral-500">Facts as of release {page.source_version}.</p>
            ) : null}
            {page.shown_version === null && v && v !== page.source_version ? (
              <p className="text-sm text-neutral-500">
                No record of this unit in release {v}; showing current facts.
              </p>
            ) : null}
          </div>
        </div>

        <section className="flex flex-col gap-3" aria-labelledby="unit-stats">
          <h2 id="unit-stats" className="text-sm uppercase tracking-wide text-neutral-400">
            Stats
          </h2>
          <StatTable stats={page.stats} />
        </section>

        <section className="flex flex-col gap-3" aria-labelledby="unit-builds">
          <h2 id="unit-builds" className="text-sm uppercase tracking-wide text-neutral-400">
            Builds
          </h2>
          {page.builds.length === 0 ? (
            <p className="text-sm text-neutral-500">Nothing, or nothing reported yet.</p>
          ) : (
            <ul className="flex flex-wrap gap-2">
              {page.builds.map((build) => (
                <li key={build.name}>
                  <Link
                    href={`/games/${shortname}/units/${build.name}`}
                    className="rounded-md border border-neutral-800 px-3 py-1.5 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
                  >
                    {build.label}
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </section>

        {page.versions.length > 0 ? (
          <section className="flex flex-col gap-3" aria-labelledby="unit-versions">
            <h2 id="unit-versions" className="text-sm uppercase tracking-wide text-neutral-400">
              Releases
            </h2>
            <ul className="flex flex-wrap gap-2">
              <li>
                <Link
                  href={`/games/${shortname}/units/${page.unit_name}`}
                  className={`rounded-md border px-3 py-1.5 text-sm transition-colors ${
                    page.shown_version
                      ? "border-neutral-800 text-neutral-300 hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
                      : "border-neutral-600 bg-neutral-900 text-neutral-100"
                  }`}
                >
                  Latest
                </Link>
              </li>
              {page.versions.map((version) => (
                <li key={version}>
                  <Link
                    href={`/games/${shortname}/units/${page.unit_name}${versionQuery(version)}`}
                    className={`rounded-md border px-3 py-1.5 text-sm transition-colors ${
                      page.shown_version === version
                        ? "border-neutral-600 bg-neutral-900 text-neutral-100"
                        : "border-neutral-800 text-neutral-300 hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
                    }`}
                  >
                    {version}
                  </Link>
                </li>
              ))}
            </ul>
            {page.versions.length >= 2 ? (
              <form
                action={`/games/${shortname}/units/${page.unit_name}/compare`}
                method="get"
                className="flex flex-wrap items-end gap-3 pt-2"
              >
                <div className="flex flex-col gap-1.5">
                  <label htmlFor="compare-left" className="text-xs uppercase tracking-wide text-neutral-400">
                    Compare
                  </label>
                  <select id="compare-left" name="left" defaultValue={page.versions[1]} className="rounded-md border border-neutral-800 bg-neutral-950 px-3 py-2 text-sm text-neutral-100">
                    {page.versions.map((version) => (
                      <option key={version} value={version}>
                        {version}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="flex flex-col gap-1.5">
                  <label htmlFor="compare-right" className="text-xs uppercase tracking-wide text-neutral-400">
                    With
                  </label>
                  <select id="compare-right" name="right" defaultValue={page.versions[0]} className="rounded-md border border-neutral-800 bg-neutral-950 px-3 py-2 text-sm text-neutral-100">
                    {page.versions.map((version) => (
                      <option key={version} value={version}>
                        {version}
                      </option>
                    ))}
                  </select>
                </div>
                <button
                  type="submit"
                  className="rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
                >
                  Compare releases
                </button>
              </form>
            ) : null}
          </section>
        ) : null}

        <p className="text-sm text-neutral-500">
          <Link href={`/games/${shortname}/units`} className="text-neutral-300 underline-offset-4 hover:underline active:underline">
            Every unit this game ships
          </Link>
          .
        </p>
      </div>
    </main>
  );
}
