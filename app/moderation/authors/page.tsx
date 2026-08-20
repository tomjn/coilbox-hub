import Link from "next/link";
import { notFound } from "next/navigation";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { archives } from "@/components/art/drawings";
import { ModerationNav } from "@/components/ModerationNav";
import {
  type AuthorAlias,
  type AuthorCount,
  AUTHOR_PAGE_SIZE,
  fetchAuthorAliases,
  fetchAuthorCounts,
  type MergeOutcome,
} from "@/lib/maps/authorMerge";
import { createClient } from "@/lib/supabase/server";
import { mergeAuthors, unmergeAuthors } from "./actions";

/**
 * Two author keys that are one person (issue #193).
 *
 * A map's author is free text out of mapinfo.lua, so one mapper arrives as
 * `Beherith`, `beherith` and `[BAR]Beherith`, and `public.author_key` folds all
 * three to one key on its own. What no rule can fold is `bherith`, a mapper who
 * changed handle, or a joint credit that split into somebody who does not exist.
 * Those are a maintainer's judgement, and `public.author_alias` records it.
 *
 * The list is ordered by map count because that is where the useful merges are.
 * A key with one map and a typo in it is worth less attention than two keys with
 * forty maps between them. Anything further down is still mergeable by typing
 * both keys into the form.
 *
 * A merge takes effect at once and nothing is resubmitted. Every read path
 * resolves through `public.resolved_author_key`, so the listing, a map's page
 * and the client lookup all follow the alias on the next request.
 *
 * ## Everything here is read with the moderator's own session
 *
 * `public.author_alias` and `public.author_map_count` are both readable by
 * anybody, because they publish nothing `public.map_author` does not. The secret
 * key is only in the two writes, and `app/moderation/authors/actions.ts` says
 * why.
 */

const BACKDROP_STRENGTH = 0.08;

const INPUT =
  "rounded-md border border-neutral-800 bg-black px-3 py-2 text-sm text-neutral-200 placeholder:text-neutral-600 focus-visible:border-neutral-500 focus-visible:outline-none";

const BUTTON =
  "rounded-md border border-neutral-700 px-4 py-2 text-sm font-medium text-neutral-200 transition-colors hover:border-neutral-500 active:border-neutral-400 hover:text-white active:text-white";

const ROW = "flex flex-col gap-1 rounded-md border border-neutral-800 bg-neutral-950 p-4 text-sm";

/**
 * What the last submission came to, in the moderator's words.
 *
 * The chained one is the reason this page answers back at all. An alias resolves
 * once and stops, so a merge into a key that is itself merged would file the
 * maps under a key nothing counts, and the maintainer would have no way to tell
 * from the list that anything was wrong.
 */
const ANSWER: Record<MergeOutcome, string> = {
  merged: "Merged. Every listing follows it from the next request.",
  chained:
    "Not merged. One end of it is already part of a merge, and an alias resolves once and stops, so a chain would file those maps under a key nothing counts. Merge into the person the existing alias points at instead.",
  refused:
    "Not merged. The two keys have to be different, and a clan tag with no name behind it is a group rather than a person.",
};

function answerFor(value: string | string[] | undefined): string | null {
  const outcome = Array.isArray(value) ? value[0] : value;

  return outcome && outcome in ANSWER ? ANSWER[outcome as MergeOutcome] : null;
}

function Alias({ alias }: { alias: AuthorAlias }) {
  return (
    <li className={ROW}>
      <div className="flex flex-wrap items-baseline justify-between gap-3">
        <p className="text-neutral-300">
          <span className="font-mono">{alias.fromKey}</span>
          <span className="text-neutral-600"> is </span>
          <span className="font-mono">{alias.toKey}</span>
        </p>
        <form action={unmergeAuthors}>
          <button
            type="submit"
            name="from"
            value={alias.fromKey}
            className="rounded-md border border-neutral-800 px-3 py-1.5 text-xs text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
          >
            Unmerge
            <span className="sr-only">
              {" "}
              {alias.fromKey} from {alias.toKey}
            </span>
          </button>
        </form>
      </div>
      {alias.note ? <p className="text-xs text-neutral-500">{alias.note}</p> : null}
      {alias.chained ? (
        // Marked rather than hidden or followed. The merge form refuses to make
        // one of these, so a row that is chained was written before that check
        // existed or by hand, and the maps under it are counting under a key
        // nobody sees.
        <p className="text-xs text-amber-400">
          {alias.toKey} is itself merged, and an alias resolves once and stops.
          Point this one at the person instead.
        </p>
      ) : null}
    </li>
  );
}

function Author({ author }: { author: AuthorCount }) {
  return (
    <li className="flex items-baseline justify-between gap-3 text-sm">
      <Link
        href={`/maps?author=${encodeURIComponent(author.key)}`}
        className="text-neutral-300 hover:underline active:underline"
      >
        {author.name}
      </Link>
      <span className="flex items-baseline gap-4">
        <span className="font-mono text-xs text-neutral-600">{author.key}</span>
        <span className="text-xs text-neutral-500">{author.maps}</span>
      </span>
    </li>
  );
}

export default async function AuthorMerges({ searchParams }: PageProps<"/moderation/authors">) {
  const supabase = await createClient();
  const { data: allowed } = await supabase.rpc("is_moderator");
  // Not a 403, for the same reason as every other moderation page.
  if (!allowed) notFound();

  const answer = answerFor((await searchParams).outcome);

  const [authors, aliases] = await Promise.all([
    fetchAuthorCounts(supabase),
    fetchAuthorAliases(supabase),
  ]);

  return (
    <main className="relative flex-1">
      <ArtBackdrop drawing={archives} strength={BACKDROP_STRENGTH} />
      <ModerationNav current="authors" />
      <div className="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <h1 className="text-3xl font-semibold tracking-tight">Authors</h1>

        <form action={mergeAuthors} className="flex flex-col gap-3">
          <p className="text-sm text-neutral-400">
            Point one key at another when they are the same person. Both are
            folded the way a credit out of an archive is folded, so the case and
            any clan tag do not matter.
          </p>
          <div className="flex flex-wrap gap-3">
            <label className="sr-only" htmlFor="merge-from">
              The key to merge away
            </label>
            <input id="merge-from" name="from" placeholder="bherith" className={`${INPUT} flex-1`} />
            <label className="sr-only" htmlFor="merge-to">
              The person it is
            </label>
            <input id="merge-to" name="to" placeholder="beherith" className={`${INPUT} flex-1`} />
          </div>
          <label className="sr-only" htmlFor="merge-note">
            Why they are one person
          </label>
          <input
            id="merge-note"
            name="note"
            placeholder="Why they are one person, for whoever reads this in a year"
            className={INPUT}
          />
          <div>
            <button type="submit" className={BUTTON}>
              Merge them
            </button>
          </div>
        </form>

        {answer ? <p className="text-sm text-neutral-300">{answer}</p> : null}

        <h2 className="text-sm text-neutral-500">
          {aliases.length === 0
            ? "Nothing has been merged yet."
            : "Merged so far. Unmerging puts the two keys back where they were."}
        </h2>

        {aliases.length === 0 ? null : (
          <ul className="flex flex-col gap-3">
            {aliases.map((alias) => (
              <Alias key={alias.fromKey} alias={alias} />
            ))}
          </ul>
        )}

        <h2 className="text-sm text-neutral-500">
          {authors.length === AUTHOR_PAGE_SIZE
            ? `The ${AUTHOR_PAGE_SIZE} authors with the most maps, which is where the merges worth making are.`
            : "Every author in the catalog, by how many maps they made."}
        </h2>

        {authors.length === 0 ? (
          <p className="rounded-md border border-neutral-800 bg-neutral-950 p-6 text-sm text-neutral-400">
            The catalog credits nobody yet.
          </p>
        ) : (
          <ul className="flex flex-col gap-2 rounded-md border border-neutral-800 bg-neutral-950 p-5">
            {authors.map((author) => (
              <Author key={author.key} author={author} />
            ))}
          </ul>
        )}
      </div>
    </main>
  );
}
