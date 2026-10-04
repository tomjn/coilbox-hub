import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { archives } from "@/components/art/drawings";
import { GameBreadcrumb } from "@/components/Breadcrumb";
import { Button } from "@/components/Button";
import { Skeleton } from "@/components/Skeleton";
import {
  gameFactionsCached,
  gamePageCached,
  gameReleasesCached,
  releaseChangesCached,
  unitNameLabelsCached,
  type UnitNameLabel,
} from "@/lib/games/cached";
import {
  groupByFaction,
  resolveReleasePair,
  type FactionGroup,
  type UnitChange,
  type UnitRef,
} from "@/lib/games/changes";
import { gameTitle } from "@/lib/games/labels";

/**
 * What changed between two releases of a game (#465).
 *
 * The pickers are a GET form to this same route, so the URL is the state and
 * nothing here needs a bundle. Groups are `<details>`: a release can change
 * hundreds of units, so a group opens by itself only when it is the only one in
 * its section, and every summary carries a count.
 *
 * `lib/games/changes.ts` explains what "added" and "removed" mean.
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
    title: `${name} release changes`,
    description: `Which units ${name} added, removed and changed between two releases.`,
  };
}

const BACKDROP_STRENGTH = 0.05;

const CONTROL =
  "w-full rounded-md border border-neutral-800 bg-card px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400";

const LINK = "underline-offset-4 hover:underline active:underline";

type Params = Promise<{ shortname: string }>;
type SearchParams = Promise<Record<string, string | string[] | undefined>>;

async function chosen(searchParams: SearchParams) {
  const query = await searchParams;
  const raw = (key: string) => {
    const value = query[key];
    return (Array.isArray(value) ? value[0] : value)?.trim() || undefined;
  };
  return { from: raw("from"), to: raw("to") };
}

/** The release pickers. They read the query string to show what is chosen, so
 *  they sit behind a boundary of their own and not the list's. */
async function Pickers({ params, searchParams }: { params: Params; searchParams: SearchParams }) {
  const { shortname } = await params;
  const game = await gamePageCached(shortname);
  if (!game) return null;
  const releases = await gameReleasesCached(shortname);

  if (releases.length < 2) {
    return (
      <p className="text-sm text-neutral-400">
        {releases.length === 0
          ? "The hub holds no releases of this game yet."
          : `The hub holds one release of this game, ${releases[0]}, so there is nothing to compare it with yet.`}
      </p>
    );
  }

  const asked = await chosen(searchParams);
  const { from, to } = resolveReleasePair(releases, asked.from, asked.to);
  return (
    <form action={`/games/${shortname}/changes`} className="flex flex-wrap items-end gap-3">
      <div className="flex min-w-40 flex-1 flex-col gap-1.5">
        <label htmlFor="changes-from" className="text-xs uppercase tracking-wide text-neutral-400">
          From
        </label>
        <select id="changes-from" name="from" defaultValue={from ?? undefined} className={CONTROL}>
          {releases.map((release) => (
            <option key={release} value={release}>
              {release}
            </option>
          ))}
        </select>
      </div>
      <div className="flex min-w-40 flex-1 flex-col gap-1.5">
        <label htmlFor="changes-to" className="text-xs uppercase tracking-wide text-neutral-400">
          To
        </label>
        <select id="changes-to" name="to" defaultValue={to ?? undefined} className={CONTROL}>
          {releases.map((release) => (
            <option key={release} value={release}>
              {release}
            </option>
          ))}
        </select>
      </div>
      <Button type="submit">Show changes</Button>
    </form>
  );
}

function Count({ count, noun }: { count: number; noun: string }) {
  return (
    <>
      {count} {noun}
    </>
  );
}

/** One heading and its groups. A lone group opens, because there is nothing
 *  else to choose between. */
function Groups<T extends UnitRef>({
  groups,
  children,
}: {
  groups: FactionGroup<T>[];
  children: (unit: T) => React.ReactNode;
}) {
  return (
    <div className="flex flex-col gap-2">
      {groups.map((group) => (
        <details key={group.key ?? "none"} open={groups.length === 1} className="rounded-md border border-neutral-800 bg-card">
          <summary className="cursor-pointer px-4 py-3 text-sm text-neutral-200">
            {group.name} <span className="text-neutral-400">({group.units.length})</span>
          </summary>
          <ul className="flex flex-col divide-y divide-neutral-900 border-t border-neutral-900">
            {group.units.map((unit) => (
              <li key={unit.unit_name} className="px-4 py-3">
                {children(unit)}
              </li>
            ))}
          </ul>
        </details>
      ))}
    </div>
  );
}

function UnitLink({ game, unit }: { game: string; unit: UnitRef }) {
  return (
    <Link href={`/games/${game}/units/${encodeURIComponent(unit.unit_name)}`} className={`text-neutral-100 ${LINK}`}>
      {unit.full_name ?? unit.unit_name}
    </Link>
  );
}

function BuildList({
  game,
  names,
  labels,
}: {
  game: string;
  names: string[];
  labels: ReadonlyMap<string, UnitNameLabel>;
}) {
  return names.map((name, index) => {
    const known = labels.get(name);
    return (
      <span key={name}>
        {index > 0 ? ", " : ""}
        {known ? (
          <Link href={`/games/${game}/units/${encodeURIComponent(known.name)}`} className={LINK}>
            {known.label}
          </Link>
        ) : (
          name
        )}
      </span>
    );
  });
}

function ChangedUnit({
  game,
  unit,
  from,
  to,
  labels,
  factionNames,
}: {
  game: string;
  unit: UnitChange;
  from: string;
  to: string;
  labels: ReadonlyMap<string, UnitNameLabel>;
  factionNames: ReadonlyMap<string, string>;
}) {
  const compare = `/games/${game}/units/${encodeURIComponent(unit.unit_name)}/compare?left=${encodeURIComponent(from)}&right=${encodeURIComponent(to)}`;
  const side = (key: string | null) => (key ? (factionNames.get(key) ?? key) : "none");
  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <UnitLink game={game} unit={unit} />
        <Link href={compare} className={`text-xs text-neutral-400 ${LINK}`}>
          Compare releases
          <span className="sr-only"> of {unit.full_name ?? unit.unit_name}</span>
        </Link>
      </div>
      <dl className="grid grid-cols-[minmax(7rem,max-content)_1fr] gap-x-4 gap-y-1 text-sm">
        {unit.renamed ? (
          <>
            <dt className="text-neutral-400">Name</dt>
            <dd className="break-words text-neutral-100">
              {unit.renamed.from ?? "-"} to {unit.renamed.to ?? "-"}
            </dd>
          </>
        ) : null}
        {unit.faction ? (
          <>
            <dt className="text-neutral-400">Faction</dt>
            <dd className="break-words text-neutral-100">
              {side(unit.faction.from)} to {side(unit.faction.to)}
            </dd>
          </>
        ) : null}
        {unit.stats.map((stat) => (
          <div key={stat.key} className="contents">
            <dt className="text-neutral-400">{stat.label}</dt>
            <dd className="break-all text-neutral-100">
              {stat.from} to {stat.to}
            </dd>
          </div>
        ))}
        {unit.builds.gained.length > 0 ? (
          <>
            <dt className="text-neutral-400">Can now build</dt>
            <dd className="text-neutral-100">
              <BuildList game={game} names={unit.builds.gained} labels={labels} />
            </dd>
          </>
        ) : null}
        {unit.builds.lost.length > 0 ? (
          <>
            <dt className="text-neutral-400">Can no longer build</dt>
            <dd className="text-neutral-100">
              <BuildList game={game} names={unit.builds.lost} labels={labels} />
            </dd>
          </>
        ) : null}
      </dl>
    </div>
  );
}

async function Changes({ params, searchParams }: { params: Params; searchParams: SearchParams }) {
  const { shortname } = await params;
  // A hidden game is invisible at every level, as on the game's own page.
  const game = await gamePageCached(shortname);
  if (!game) notFound();

  const releases = await gameReleasesCached(shortname);
  const asked = await chosen(searchParams);
  const { from, to, notes } = resolveReleasePair(releases, asked.from, asked.to);
  if (!from || !to) return null;

  const notices = [...notes];
  if (from === to) {
    return (
      <>
        <Notices notes={notices} />
        <p className="text-sm text-neutral-400">
          Both pickers name {from}. Choose two different releases to see what changed.
        </p>
      </>
    );
  }
  if (releases.indexOf(from) < releases.indexOf(to)) {
    notices.push(
      `${from} was reported more recently than ${to}, so "added" means in ${to} and not in ${from}.`,
    );
  }

  const [changes, factions, labels] = await Promise.all([
    releaseChangesCached(shortname, from, to),
    gameFactionsCached(shortname),
    unitNameLabelsCached(shortname),
  ]);
  if (!changes) {
    return (
      <p className="text-sm text-red-400">
        The catalog could not be read just now. Try again in a moment.
      </p>
    );
  }

  const factionNames = new Map(factions.map((faction) => [faction.key, faction.name]));
  const added = groupByFaction(changes.added, factions);
  const removed = groupByFaction(changes.removed, factions);
  const changed = groupByFaction(changes.changed, factions);

  return (
    <>
      <Notices notes={notices} />
      <p className="text-neutral-300">
        From {from} to {to}: <Count count={changes.added.length} noun="added" />,{" "}
        <Count count={changes.removed.length} noun="removed" />,{" "}
        <Count count={changes.changed.length} noun="changed" />, {changes.unchanged} unchanged.
      </p>

      <section className="flex flex-col gap-3" aria-labelledby="changes-added">
        <h2 id="changes-added" className="text-sm uppercase tracking-wide text-neutral-400">
          Added in {to} ({changes.added.length})
        </h2>
        {added.length === 0 ? (
          <p className="text-sm text-neutral-400">No unit was added.</p>
        ) : (
          <Groups groups={added}>{(unit) => <UnitLink game={shortname} unit={unit} />}</Groups>
        )}
      </section>

      <section className="flex flex-col gap-3" aria-labelledby="changes-removed">
        <h2 id="changes-removed" className="text-sm uppercase tracking-wide text-neutral-400">
          Removed in {to} ({changes.removed.length})
        </h2>
        {removed.length === 0 ? (
          <p className="text-sm text-neutral-400">No unit was removed.</p>
        ) : (
          <Groups groups={removed}>{(unit) => <UnitLink game={shortname} unit={unit} />}</Groups>
        )}
      </section>

      <section className="flex flex-col gap-3" aria-labelledby="changes-changed">
        <h2 id="changes-changed" className="text-sm uppercase tracking-wide text-neutral-400">
          Changed ({changes.changed.length})
        </h2>
        {changed.length === 0 ? (
          <p className="text-sm text-neutral-400">No unit that both releases hold has changed.</p>
        ) : (
          <Groups groups={changed}>
            {(unit) => (
              <ChangedUnit
                game={shortname}
                unit={unit}
                from={from}
                to={to}
                labels={labels}
                factionNames={factionNames}
              />
            )}
          </Groups>
        )}
      </section>

      <p className="text-sm text-neutral-400">
        A unit counts as added or removed when one release holds a record of it and the other does
        not. A release the hub was only told part of can show units as removed that it still ships.
      </p>
    </>
  );
}

function Notices({ notes }: { notes: string[] }) {
  return notes.length === 0 ? null : (
    <ul className="flex flex-col gap-1 text-sm text-neutral-300">
      {notes.map((note) => (
        <li key={note}>{note}</li>
      ))}
    </ul>
  );
}

/** The route. The backdrop and the heading never change, so they are drawn
 *  at once. Everything that reads `params` or the query string sits behind a
 *  boundary, and the list has one apart from the pickers so that choosing a
 *  pair swaps the list for stand-ins and leaves the rest alone. */
export default function ReleaseChanges({ params, searchParams }: { params: Params; searchParams: SearchParams }) {
  return (
    <main id="main-content" tabIndex={-1} className="relative flex-1">
      <ArtBackdrop drawing={archives} strength={BACKDROP_STRENGTH} />
      <div className="relative z-10 mx-auto flex w-full max-w-5xl flex-col gap-8 px-6 py-12">
        <Suspense fallback={<Skeleton className="h-5 w-32" />}>
          <GameBreadcrumb params={params} current="Release changes" />
        </Suspense>

        <div className="flex flex-col gap-2">
          <h1 className="text-3xl font-semibold tracking-tight">Release changes</h1>
          <p className="text-neutral-400">
            Which units one release added, removed or changed compared with another.
          </p>
        </div>

        <div className="flex flex-col gap-4 border-b border-neutral-900 pb-6">
          <Suspense fallback={<Skeleton className="h-16 w-full" />}>
            <Pickers params={params} searchParams={searchParams} />
          </Suspense>
        </div>

        <Suspense
          fallback={
            <div className="flex flex-col gap-3" aria-busy="true">
              <Skeleton className="h-6 w-64" />
              <Skeleton className="h-12 w-full" />
              <Skeleton className="h-12 w-full" />
              <Skeleton className="h-12 w-full" />
            </div>
          }
        >
          <Changes params={params} searchParams={searchParams} />
        </Suspense>
      </div>
    </main>
  );
}
