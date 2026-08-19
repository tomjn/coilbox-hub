import Link from "next/link";
import { notFound } from "next/navigation";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { archives } from "@/components/art/drawings";
import { ModerationNav } from "@/components/ModerationNav";
import {
  type ConflictedMap,
  fetchMapConflicts,
  MAP_SEARCH_LIMIT,
  type ReportedSource,
  searchMaps,
} from "@/lib/maps/moderation";
import { createAdminClient } from "@/lib/supabase/admin";
import { createClient } from "@/lib/supabase/server";
import { clearHeldFacts } from "./actions";

/**
 * The maps two clients disagree about (issue #193).
 *
 * A conflict means two installs hold different bytes under one canonical name.
 * That is never a new release: a mapper who changes a map releases it as a new
 * map with a version appended, so one name is one archive permanently. It is a
 * corrupt or modified install, and those two players are already out of sync
 * with each other in a lobby and would desync in a game.
 *
 * `public.submit_map_facts` refuses the second set of facts and records the
 * disagreement, which is the right answer while the facts the hub holds are the
 * good ones. This page exists for when they are not. A map collecting report
 * after report is a map whose stored row may be the odd one out, and the only
 * way to find out is for somebody to look at it.
 *
 * Nothing here decides that on a moderator's behalf, and there is no way to act
 * on the page at once. `lib/maps/moderation.ts` and `lib/assets/sourceConflict.ts`
 * carry the reasoning.
 *
 * ## The other half of the page
 *
 * The find a map box is how a moderator reaches one map's view, where the
 * curated tags are edited. Nearly every map in the catalog has no conflict and
 * would otherwise be a URL somebody had to build by hand out of a slug they
 * cannot see.
 */

const BACKDROP_STRENGTH = 0.08;

const CARD = "flex flex-col gap-3 rounded-md border border-neutral-800 bg-neutral-950 p-5";

const INPUT =
  "rounded-md border border-neutral-800 bg-black px-3 py-2 text-sm text-neutral-200 placeholder:text-neutral-600 focus-visible:border-neutral-500 focus-visible:outline-none";

const BUTTON =
  "rounded-md border border-neutral-700 px-4 py-2 text-sm font-medium text-neutral-200 transition-colors hover:border-neutral-500 hover:text-white";

/** Red, because a cleared map leaves the catalog until a client reports it
 *  again, and because nothing puts its curated tags back. */
const CLEAR =
  "rounded-md border border-red-900 px-3 py-1.5 text-xs text-red-400 transition-colors hover:border-red-700 hover:text-red-300";

/** A source hash is 64 characters and there are two on every line. The first
 *  stretch tells two apart while reading, and the whole of it is on the title
 *  for anybody who has to match it against a file. */
function shortHash(hash: string): string {
  return hash.slice(0, 12);
}

function when(at: string): string {
  return at.replace("T", " ").slice(0, 16);
}

function reportedLine(count: number): string {
  return count === 1 ? "One report." : `${count} reports.`;
}

function Report({ report }: { report: ReportedSource }) {
  return (
    <li className="flex flex-wrap items-baseline gap-x-4 gap-y-1 text-xs text-neutral-500">
      <span className="text-neutral-400" title={report.sourceArchive}>
        {report.sourceArchive}
      </span>
      <span title={report.heldSourceHash}>held {shortHash(report.heldSourceHash)}</span>
      <span className="text-amber-400" title={report.reportedSourceHash}>
        reported {shortHash(report.reportedSourceHash)}
      </span>
      {report.reportedBy ? (
        // The account is a link into the picture trail, which is the one place
        // in the hub that shows everything one account is behind.
        <Link
          href={`/moderation/trail?account=${report.reportedBy}`}
          title={report.reportedBy}
          className="underline decoration-neutral-700 underline-offset-2 transition-colors hover:text-neutral-300"
        >
          by {report.reportedBy.slice(0, 8)}
        </Link>
      ) : (
        <span>by an account that has closed</span>
      )}
      <span>{when(report.at)}</span>
    </li>
  );
}

function Conflicted({ map }: { map: ConflictedMap }) {
  return (
    <li className={CARD}>
      <div className="flex flex-wrap items-baseline justify-between gap-3">
        <Link href={`/moderation/maps/${map.slug}`} className="text-base font-medium hover:underline">
          {map.mapName}
        </Link>
        <span className="text-xs text-neutral-600">{reportedLine(map.reports.length)}</span>
      </div>

      <ul className="flex flex-col gap-1">
        {map.reports.map((report) => (
          <Report key={report.id} report={report} />
        ))}
      </ul>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <Link
          href={`/map/${map.slug}`}
          className="text-xs text-neutral-500 transition-colors hover:text-neutral-300"
        >
          What the hub says about it
        </Link>
        <form action={clearHeldFacts}>
          <button
            type="submit"
            name="map"
            value={map.id}
            title="Only when the facts the hub holds are the wrong ones. The map leaves the catalog until a client reports it again, and its curated tags go with it."
            className={CLEAR}
          >
            Forget what the hub holds
            <span className="sr-only"> about {map.mapName}</span>
          </button>
        </form>
      </div>
    </li>
  );
}

export default async function MapConflicts({ searchParams }: PageProps<"/moderation/maps">) {
  const supabase = await createClient();
  const { data: allowed } = await supabase.rpc("is_moderator");
  // Not a 403, for the same reason as every other moderation page: whether this
  // page exists is not something a stranger needs to learn.
  if (!allowed) notFound();

  const { q } = await searchParams;
  const term = typeof q === "string" ? q : "";

  // The conflicts with the secret key, because `public.map_source_conflict` is
  // server side on both sides. The search with the moderator's own client,
  // because the catalog is public and nothing about a map's name is private.
  const [conflicts, matches] = await Promise.all([
    fetchMapConflicts(createAdminClient()),
    searchMaps(supabase, term),
  ]);

  return (
    <main className="relative flex-1">
      <ArtBackdrop drawing={archives} strength={BACKDROP_STRENGTH} />
      <ModerationNav current="maps" />
      <div className="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <h1 className="text-3xl font-semibold tracking-tight">Maps</h1>

        <form className="flex flex-wrap gap-3">
          <label className="sr-only" htmlFor="find-a-map">
            Find a map by name
          </label>
          <input
            id="find-a-map"
            name="q"
            defaultValue={term}
            placeholder="Find a map by name"
            className={`${INPUT} flex-1`}
          />
          <button type="submit" className={BUTTON}>
            Find it
          </button>
        </form>

        {term === "" ? null : matches.length === 0 ? (
          <p className="text-sm text-neutral-500">No map is called that.</p>
        ) : (
          <ul className="flex flex-col gap-1">
            {matches.map((match) => (
              <li key={match.slug}>
                <Link
                  href={`/moderation/maps/${match.slug}`}
                  className="text-sm text-neutral-300 hover:underline"
                >
                  {match.mapName}
                </Link>
              </li>
            ))}
            {matches.length === MAP_SEARCH_LIMIT ? (
              <li className="text-xs text-neutral-600">
                The first {MAP_SEARCH_LIMIT}. Type more of the name to narrow it.
              </li>
            ) : null}
          </ul>
        )}

        <h2 className="text-sm text-neutral-500">
          Maps two clients disagree about, the most reported first. Two installs
          holding different bytes under one name is a corrupt or modified install
          rather than a new release. Forget what the hub holds only when the
          stored facts are the odd one out.
        </h2>

        {conflicts.length === 0 ? (
          <p className="rounded-md border border-neutral-800 bg-neutral-950 p-6 text-sm text-neutral-400">
            Nobody disagrees about anything.
          </p>
        ) : (
          <ul className="flex flex-col gap-4">
            {conflicts.map((map) => (
              <Conflicted key={map.id} map={map} />
            ))}
          </ul>
        )}
      </div>
    </main>
  );
}
