import type { Metadata } from "next";
import Link from "next/link";
import { buttonClass } from "@/components/Button";
import { refuseModeration } from "@/lib/access/secondFactor";
import { ArtBackdrop } from "@/components/art/ArtBackdrop";
import { archives } from "@/components/art/drawings";
import { ModerationNav } from "@/components/ModerationNav";
import {
  type AuthorAlias,
  type AuthorCount,
  AUTHOR_PAGE_SIZE,
  aliasesByTarget,
  fetchAuthorAliases,
  fetchAuthorCounts,
  fetchAuthors,
  fetchSpellings,
  type MergeOutcome,
  nameChoices,
  selectedKeys,
} from "@/lib/maps/authorMerge";
import { createClient } from "@/lib/supabase/server";
import { mergeAuthors, unmergeAuthors } from "./actions";

export const metadata: Metadata = {
  title: "Authors - Moderation",
};

/**
 * Merging the keys that are one person (issues #193 and #442).
 *
 * A map's author is free text out of mapinfo.lua, so one mapper arrives as
 * `Beherith`, `[teh]Beherith (mysterme@gmail.com)` and `original from Beherith`.
 * `public.author_key` folds the first two to one key, and the third is a key of
 * its own until a moderator merges it.
 *
 * The page works in three steps, with no client JavaScript:
 *
 * 1. Search for a name. The list shows every author whose key contains it, with
 *    the keys already merged into each one listed under it, so a merged key is
 *    never shown as an author of its own.
 * 2. Tick the keys that are one person and press "Merge selected". That is a
 *    GET back to this page with the ticked keys in the URL.
 * 3. The merge form opens above the list. Pick which key to keep and the name
 *    to show, then merge.
 *
 * A merge takes effect at once and nothing is resubmitted. Every read path
 * resolves through `public.resolved_author_key`, so the listing, a map's page
 * and the client lookup all follow the alias on the next request.
 *
 * ## Everything here is read with the moderator's own session
 *
 * `public.author_alias`, `public.author_map_count` and `public.author_spelling`
 * are readable by anybody, because they publish nothing `public.map_author`
 * does not. The secret
 * key is only in the two writes, and `app/moderation/authors/actions.ts` says
 * why.
 */

const BACKDROP_STRENGTH = 0.08;

const INPUT =
  "rounded-md border border-neutral-800 bg-black px-3 py-2 text-sm text-neutral-200 placeholder:text-neutral-400 focus-visible:border-neutral-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400";

const BUTTON = buttonClass();

const ROW = "flex flex-col gap-1 rounded-md border border-neutral-800 bg-card p-4 text-sm";

const CHOICE = "flex min-w-0 items-baseline gap-3 py-1 text-sm text-neutral-300";

/** The longest name `public.author_name` accepts. Its check is the one
 *  `public.map_author.raw` carries. */
const NAME_MAX_LENGTH = 256;

/**
 * What the last submission came to, in the moderator's words.
 *
 * The chained one is the reason this page answers back at all. An alias
 * resolves once and stops, so a merge into a key that is itself merged would
 * file the maps under a key nothing counts, and the maintainer would have no way
 * to tell from the list that anything was wrong.
 */
const ANSWER: Record<MergeOutcome, string> = {
  merged: "Merged. Every listing follows it from the next request.",
  chained:
    "Not merged. The key you kept is itself merged into another author. Keep that author's key instead.",
  refused: "Not merged. Tick at least two keys, or one key and a name to show it under.",
};

function first(value: string | string[] | undefined): string {
  return (Array.isArray(value) ? value[0] : value) ?? "";
}

function answerFor(value: string | string[] | undefined): string | null {
  const outcome = first(value);

  return outcome in ANSWER ? ANSWER[outcome as MergeOutcome] : null;
}

function pageHref(search: string): string {
  return search === "" ? "/moderation/authors" : `/moderation/authors?${new URLSearchParams({ q: search })}`;
}

/**
 * The form that does the merge, for the keys ticked in the list.
 *
 * The key kept defaults to the one with the most maps, and the name defaults
 * to what that author is shown under now. With one key ticked there is nothing
 * to merge, so this renames that author.
 */
function MergeForm({
  authors,
  choices,
  search,
}: {
  authors: AuthorCount[];
  choices: string[];
  search: string;
}) {
  const kept = authors.reduce((best, author) => (author.maps > best.maps ? author : best));
  const renaming = authors.length === 1;
  const names = [...new Set([kept.name, ...choices])];

  return (
    <form action={mergeAuthors} className={`${ROW} gap-5`}>
      <h2 className="text-lg font-semibold text-neutral-200">
        {renaming ? "Rename this author" : `Merge ${authors.length} keys into one author`}
      </h2>
      <input type="hidden" name="q" value={search} />
      {authors.map((author) => (
        <input key={author.key} type="hidden" name="key" value={author.key} />
      ))}

      {renaming ? (
        <input type="hidden" name="into" value={kept.key} />
      ) : (
        <fieldset className="flex flex-col gap-1">
          <legend className="mb-1 text-sm text-neutral-400">
            Keep this key. The others point at it. A credit that names two
            people, like <span className="font-mono">beherith/the_yak</span>,
            counts for whichever person it is merged into.
          </legend>
          {authors.map((author) => (
            <label key={author.key} className={CHOICE}>
              <input
                type="radio"
                name="into"
                value={author.key}
                defaultChecked={author.key === kept.key}
                className="size-4 shrink-0 self-center"
              />
              <span className="min-w-0 break-words font-mono">{author.key}</span>
              <span className="ml-auto shrink-0 text-xs text-neutral-400">
                {author.maps} {author.maps === 1 ? "map" : "maps"}
              </span>
            </label>
          ))}
        </fieldset>
      )}

      <fieldset className="flex flex-col gap-1">
        <legend className="mb-1 text-sm text-neutral-400">Show them as</legend>
        {names.map((name) => (
          <label key={name} className={CHOICE}>
            <input
              type="radio"
              name="name"
              value={name}
              defaultChecked={name === kept.name}
              className="size-4 shrink-0 self-center"
            />
            <span className="min-w-0 break-words">{name}</span>
          </label>
        ))}
        <label className="mt-2 flex flex-col gap-1 text-sm text-neutral-400" htmlFor="typed-name">
          Or type a name, which wins over the one picked above
        </label>
        <input
          id="typed-name"
          name="typed_name"
          maxLength={NAME_MAX_LENGTH}
          placeholder="Beherith"
          className={INPUT}
        />
      </fieldset>

      <div className="flex flex-col gap-1">
        <label className="text-sm text-neutral-400" htmlFor="merge-note">
          Why they are one person, for whoever reads this in a year
        </label>
        <input id="merge-note" name="note" className={INPUT} />
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <button type="submit" className={BUTTON}>
          {renaming ? "Rename" : "Merge them"}
        </button>
        <Link href={pageHref(search)} className={buttonClass("ghost")}>
          Cancel
        </Link>
      </div>
    </form>
  );
}

function Author({
  author,
  merged,
  ticked,
}: {
  author: AuthorCount;
  merged: AuthorAlias[];
  ticked: boolean;
}) {
  return (
    <li className="flex items-start gap-3 py-1.5 text-sm">
      <label className="flex min-w-0 flex-1 items-start gap-3">
        <input
          type="checkbox"
          name="merge"
          value={author.key}
          defaultChecked={ticked}
          className="mt-0.5 size-4 shrink-0"
        />
        <span className="flex min-w-0 flex-col gap-0.5">
          <span className="break-words text-neutral-200">{author.name}</span>
          <span className="break-words font-mono text-xs text-neutral-400">{author.key}</span>
          {merged.length > 0 ? (
            <span className="break-words text-xs text-neutral-400">
              Merged in: <span className="font-mono">{merged.map((alias) => alias.fromKey).join(", ")}</span>
            </span>
          ) : null}
        </span>
      </label>
      <Link
        href={`/maps?author=${encodeURIComponent(author.key)}`}
        className="shrink-0 text-xs text-neutral-400 hover:underline active:underline"
      >
        {author.maps} {author.maps === 1 ? "map" : "maps"}
      </Link>
    </li>
  );
}

function MergedGroup({ toKey, aliases }: { toKey: string; aliases: AuthorAlias[] }) {
  return (
    <li className={ROW}>
      <p className="break-words text-neutral-300">
        Merged into <span className="font-mono">{toKey}</span>
      </p>
      <ul className="flex flex-col gap-1">
        {aliases.map((alias) => (
          <li key={alias.fromKey} className="flex flex-col gap-1">
            <div className="flex flex-wrap items-baseline justify-between gap-3">
              <span className="min-w-0 break-words font-mono text-neutral-300">{alias.fromKey}</span>
              <form action={unmergeAuthors}>
                <button
                  type="submit"
                  name="from"
                  value={alias.fromKey}
                  className={buttonClass("ghost", { size: "sm" })}
                >
                  Unmerge
                  <span className="sr-only">
                    {" "}
                    {alias.fromKey} from {alias.toKey}
                  </span>
                </button>
              </form>
            </div>
            {alias.note ? <p className="text-xs text-neutral-400">{alias.note}</p> : null}
            {alias.chained ? (
              // Marked rather than hidden or followed. The merge refuses to make
              // one of these, so a row that is chained was written before that
              // check existed or by hand, and the maps under it are counting
              // under a key nobody sees.
              <p className="text-xs text-amber-400">
                {alias.toKey} is itself merged, and an alias resolves once and stops.
                Merge this key into the person instead.
              </p>
            ) : null}
          </li>
        ))}
      </ul>
    </li>
  );
}

export default async function AuthorMerges({ searchParams }: PageProps<"/moderation/authors">) {
  const supabase = await createClient();
  const { data: allowed } = await supabase.rpc("is_moderator");
  // Not a 403, for the same reason as every other moderation page.
  if (!allowed) await refuseModeration("/moderation/authors");

  const params = await searchParams;
  const answer = answerFor(params.outcome);
  const search = first(params.q).trim();
  const ticked = selectedKeys(params.merge);

  const [authors, aliases, selected, spellings] = await Promise.all([
    fetchAuthorCounts(supabase, search),
    fetchAuthorAliases(supabase),
    fetchAuthors(supabase, ticked),
    fetchSpellings(supabase, ticked),
  ]);

  const merged = aliasesByTarget(aliases);
  const tickedSet = new Set(ticked);

  return (
    <main id="main-content" tabIndex={-1} className="relative flex-1">
      <ArtBackdrop drawing={archives} strength={BACKDROP_STRENGTH} />
      <ModerationNav current="authors" />
      <div className="relative z-10 mx-auto flex w-full max-w-3xl flex-col gap-6 px-6 py-12">
        <h1 className="text-3xl font-semibold tracking-tight">Authors</h1>

        <p className="text-sm text-neutral-400">
          Each line is one author, under the key their credits fold to once case
          and clan tags are removed. A credit like{" "}
          <span className="font-mono">original from Beherith</span> folds to a key
          of its own. Search for a name, tick every key that is that person, and
          merge them.
        </p>

        {answer ? <p className="text-sm text-neutral-300">{answer}</p> : null}

        {selected.length > 0 ? (
          <MergeForm authors={selected} choices={nameChoices(spellings)} search={search} />
        ) : null}

        <form action="/moderation/authors" className="flex flex-wrap items-end gap-3">
          <div className="flex min-w-48 flex-1 flex-col gap-1.5">
            <label htmlFor="author-search" className="text-sm text-neutral-400">
              Find keys containing
            </label>
            <input
              id="author-search"
              type="search"
              name="q"
              defaultValue={search}
              placeholder="beherith"
              className={INPUT}
            />
          </div>
          <button type="submit" className={buttonClass("ghost")}>
            Search
          </button>
          {search !== "" ? (
            <Link href="/moderation/authors" className={buttonClass("ghost")}>
              Clear
            </Link>
          ) : null}
        </form>

        <h2 className="text-sm text-neutral-400">
          {search !== ""
            ? authors.length === AUTHOR_PAGE_SIZE
              ? `The ${AUTHOR_PAGE_SIZE} keys containing "${search}" with the most maps.`
              : `Every key containing "${search}", by how many maps they made.`
            : authors.length === AUTHOR_PAGE_SIZE
              ? `The ${AUTHOR_PAGE_SIZE} authors with the most maps. Search to find the rest.`
              : "Every author in the catalog, by how many maps they made."}
        </h2>

        {authors.length === 0 ? (
          <p className="rounded-md border border-neutral-800 bg-card p-6 text-sm text-neutral-400">
            {search !== "" ? `No key contains "${search}".` : "The catalog credits nobody yet."}
          </p>
        ) : (
          <form action="/moderation/authors" className="flex flex-col gap-3">
            {search !== "" ? <input type="hidden" name="q" value={search} /> : null}
            <ul className="flex flex-col rounded-md border border-neutral-800 bg-card p-5">
              {authors.map((author) => (
                <Author
                  key={author.key}
                  author={author}
                  merged={merged.get(author.key) ?? []}
                  ticked={tickedSet.has(author.key)}
                />
              ))}
            </ul>
            <div>
              <button type="submit" className={BUTTON}>
                Merge selected
              </button>
            </div>
          </form>
        )}

        <h2 className="text-sm text-neutral-400">
          {aliases.length === 0
            ? "Nothing has been merged yet."
            : "Merged so far. Unmerging a key makes it an author of its own again."}
        </h2>

        {aliases.length === 0 ? null : (
          <ul className="flex flex-col gap-3">
            {[...merged.entries()].map(([toKey, group]) => (
              <MergedGroup key={toKey} toKey={toKey} aliases={group} />
            ))}
          </ul>
        )}
      </div>
    </main>
  );
}
