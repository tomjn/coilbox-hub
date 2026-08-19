import type { SupabaseClient } from "@supabase/supabase-js";

/**
 * Saying that two author keys are one person, and taking it back (issue #193).
 *
 * `public.author_key` gets most of the way there on its own: it folds case,
 * strips a clan tag and collapses whitespace, so `[BAR]Beherith` and `beherith`
 * are already one key. What no rule applied to a string can know is that
 * `bherith` is a typo, that somebody changed handle, or that a joint credit
 * split into a person who does not exist. Those are a maintainer's judgement,
 * and `public.author_alias` is where the judgement is recorded.
 *
 * ## The list is ordered by map count
 *
 * Which is #193's own reasoning: a key with one map and a typo in it is worth
 * less attention than two keys with forty maps between them. So the page shows
 * the busiest keys and a moderator spots two that are obviously one person.
 * Everything below is still mergeable by typing both keys in, because a
 * moderator who already knows about a mapper should not have to page to them.
 *
 * ## One hop, and this refuses to make a chain
 *
 * `public.author_alias` resolves `from_key` to `to_key` and stops. The schema
 * says why: a chain is a loop waiting to happen, and a maintainer merging into
 * an alias rather than into a person is the mistake to notice rather than to
 * follow. Noticing it has to happen somewhere, and here is the only place a
 * chain can be created.
 *
 * So a merge is refused when either end of it would leave a chain, and refused
 * rather than quietly rewritten. Rewriting the target to wherever the existing
 * alias points would be following the mistake: the merge that gets recorded is
 * then not the one that was asked for, and the maintainer is never told that the
 * key they named is not a person. Both directions are checked, because either
 * one makes a chain. Merging into a key that is itself merged is the obvious
 * one. Merging away a key that other aliases already point at is the same thing
 * seen from the other end, and it is the one that arrives by accident weeks
 * later.
 */

/**
 * How many author keys the merge list shows.
 *
 * The catalog holds thousands of keys and nearly all of them have one map. This
 * is a list somebody reads down looking for two lines that are the same person,
 * so it is as long as that stays worth doing and no longer.
 */
export const AUTHOR_PAGE_SIZE = 200;

/**
 * How many recorded merges the page shows.
 *
 * A ceiling rather than a page size. Every merge is somebody's typing, so the
 * table grows at the speed of a person, and a hub with more than this many is a
 * hub that needs paging designed rather than a number raised.
 */
export const ALIAS_PAGE_SIZE = 500;

/** One author key, as the merge list shows it. */
export interface AuthorCount {
  key: string;
  /** The spelling `public.author_display_name` settled on, which is the name
   *  this mapper is shown under everywhere else. */
  name: string;
  maps: number;
}

/**
 * The busiest author keys first.
 *
 * `public.author_map_count` is the grouped read, and the migration says why it
 * is a view: PostgREST cannot group, and counting in TypeScript would mean
 * fetching every credit row in the catalog to produce a few hundred lines.
 *
 * The key breaks ties, so two authors with the same number of maps come back in
 * the same order on every request rather than in whichever order the planner
 * happened to produce.
 */
export async function fetchAuthorCounts(
  supabase: SupabaseClient,
  limit = AUTHOR_PAGE_SIZE,
): Promise<AuthorCount[]> {
  const { data } = await supabase
    .from("author_map_count")
    .select("key, name, maps")
    .order("maps", { ascending: false })
    .order("key", { ascending: true })
    .limit(limit);

  return (data ?? []) as unknown as AuthorCount[];
}

/** One recorded merge. */
export interface AuthorAlias {
  fromKey: string;
  toKey: string;
  note: string | null;
  setAt: string;
  /** Whether this row's target is itself merged onward, which is the one hop
   *  rule broken. {@link mergeAuthorKeys} refuses to create one, so this can
   *  only be a row written before that check existed or by hand. The page marks
   *  it rather than hiding it, because a reader following the alias would
   *  otherwise land on a key nothing counts under. */
  chained: boolean;
}

interface AliasRow {
  from_key: string;
  to_key: string;
  note: string | null;
  set_at: string;
}

/**
 * Mark every alias whose target is itself an alias.
 *
 * Pure, and over the whole table rather than per row, because a chain is a
 * relationship between two rows and cannot be seen from either one alone.
 */
export function markChains(rows: AliasRow[]): AuthorAlias[] {
  const merged = new Set(rows.map((row) => row.from_key));

  return rows.map((row) => ({
    fromKey: row.from_key,
    toKey: row.to_key,
    note: row.note,
    setAt: row.set_at,
    chained: merged.has(row.to_key),
  }));
}

/** Every merge a maintainer has recorded, by the key that was merged away. */
export async function fetchAuthorAliases(
  supabase: SupabaseClient,
  limit = ALIAS_PAGE_SIZE,
): Promise<AuthorAlias[]> {
  const { data } = await supabase
    .from("author_alias")
    .select("from_key, to_key, note, set_at")
    .order("from_key", { ascending: true })
    .limit(limit);

  return markChains((data ?? []) as unknown as AliasRow[]);
}

/**
 * The key a typed name folds to, as the database folds it.
 *
 * `public.author_key` and never a copy of it here.
 * `20260818110000_author_keys.sql` argues that at length, and names the failure
 * a second copy causes: an alias recorded under a key a hair different from the
 * stored one, matching nothing, with everything about the row looking correct.
 *
 * Only the fold, and deliberately not `public.resolved_author_key` after it. A
 * merge is about the key as it was written down, so resolving the key being
 * merged away would record an alias from whatever it already points at.
 */
async function foldAuthorKey(supabase: SupabaseClient, typed: string): Promise<string> {
  const { data } = await supabase.rpc("author_key", { credit: typed });

  return typeof data === "string" ? data : "";
}

/**
 * What a merge came to.
 *
 * `chained` is its own answer rather than a refusal, because it is the one a
 * maintainer needs told: the key they aimed at is an alias, not a person, and
 * the merge they meant is into whoever that key points at.
 */
export type MergeOutcome = "merged" | "chained" | "refused";

/**
 * Record that two keys are one person.
 *
 * An upsert on `from_key`, so pointing a key somewhere else is the same act as
 * merging it in the first place. The table is keyed on `from_key` alone, which
 * is the one hop rule as a primary key: a key can be merged into one person and
 * not into two.
 *
 * A key that folds to nothing is refused. `[BAR]` with no name behind it is a
 * group rather than a person, and an alias from or to an empty string would
 * match every credit that keyed to nothing, which is none of them, forever.
 */
export async function mergeAuthorKeys(
  supabase: SupabaseClient,
  typedFrom: string,
  typedTo: string,
  note: string,
): Promise<MergeOutcome> {
  const fromKey = await foldAuthorKey(supabase, typedFrom);
  const toKey = await foldAuthorKey(supabase, typedTo);

  if (fromKey === "" || toKey === "") return "refused";
  // `author_alias_not_self_check` refuses this too. It is caught here so the
  // answer is a refusal rather than a database error nobody sees.
  if (fromKey === toKey) return "refused";

  // Two reads rather than one filter holding both, because either half is enough
  // to refuse and because a key typed by hand is not something to interpolate
  // into a PostgREST filter expression.
  const { data: targetMerged } = await supabase
    .from("author_alias")
    .select("from_key")
    .eq("from_key", toKey)
    .maybeSingle();

  const { data: sourcePointedAt } = await supabase
    .from("author_alias")
    .select("from_key")
    .eq("to_key", fromKey)
    .limit(1)
    .maybeSingle();

  if (targetMerged || sourcePointedAt) return "chained";

  const { error } = await supabase
    .from("author_alias")
    .upsert(
      { from_key: fromKey, to_key: toKey, note: note.trim() === "" ? null : note.trim() },
      { onConflict: "from_key" },
    );

  return error ? "refused" : "merged";
}

/**
 * Withdraw a merge, so the two keys count separately again.
 *
 * A delete rather than a flag, which is the grant #193 added and the migration
 * says why: an alias carries no knowledge worth keeping once it is withdrawn.
 * The evidence for the merge is `public.map_author.raw`, which was never
 * touched, so unmerging loses nothing a maintainer would want back.
 */
export async function unmergeAuthorKey(
  supabase: SupabaseClient,
  fromKey: string,
): Promise<boolean> {
  const { error } = await supabase.from("author_alias").delete().eq("from_key", fromKey);

  return !error;
}
