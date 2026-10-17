import type { SupabaseClient } from "@supabase/supabase-js";

/**
 * Saying that several author keys are one person, naming them, and taking a
 * merge back (issues #193 and #442).
 *
 * `public.author_key` gets some of the way on its own: it folds case, strips a
 * clan tag and collapses whitespace, so `[BAR]Beherith` and `beherith` are
 * already one key. What no rule applied to a string can know is that `bherith`
 * is a typo, that `original from Beherith` is Beherith, or that somebody
 * changed handle. Those are a maintainer's judgement, and `public.author_alias`
 * is where the judgement is recorded.
 *
 * ## Picked from a list
 *
 * The list is ordered by map count, which is #193's reasoning: two keys with
 * forty maps between them are worth more attention than a typo with one. A
 * search narrows it to the keys containing a name, which is how a moderator
 * finds every `... Beherith ...` key, most of which have one map each. They
 * tick the ones that are one person and merge them in one go.
 *
 * ## One hop
 *
 * `public.author_alias` resolves `from_key` to `to_key` and stops.
 * `public.merge_author_keys` keeps that true: it refuses to merge into a key
 * that is itself merged, and repoints aliases into a key it merges away.
 * `20261018140000_author_merging.sql` says why.
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
 * The busiest author keys first, optionally only those containing a search.
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
  search?: string,
  limit = AUTHOR_PAGE_SIZE,
): Promise<AuthorCount[]> {
  let query = supabase.from("author_map_count").select("key, name, maps");

  const pattern = searchPattern(search);
  if (pattern) query = query.ilike("key", pattern);

  const { data } = await query
    .order("maps", { ascending: false })
    .order("key", { ascending: true })
    .limit(limit);

  return (data ?? []) as unknown as AuthorCount[];
}

/**
 * An `ilike` pattern matching the search anywhere in a key, or null for no
 * search.
 *
 * The three characters `like` treats as special are escaped, so `the_yak`
 * matches `the_yak` and not `the-yak`.
 */
export function searchPattern(search: string | undefined): string | null {
  const trimmed = (search ?? "").trim();
  if (trimmed === "") return null;

  return `%${trimmed.replace(/[\\%_]/g, (character) => `\\${character}`)}%`;
}

/**
 * The keys a moderator ticked, read out of the URL.
 *
 * Once each and in the order they came, so the merge form lists them the way
 * the list did.
 */
export function selectedKeys(value: string | string[] | undefined): string[] {
  const values = Array.isArray(value) ? value : value === undefined ? [] : [value];

  return [...new Set(values.filter((key) => key.trim() !== ""))];
}

/**
 * The authors behind the ticked keys, in the order they were ticked.
 *
 * One read per key rather than one `in` filter, because a key is free text out
 * of an archive and a quote in one would break the filter expression. A key
 * that is no longer an author of its own, because it was merged since the list
 * was drawn, is left out.
 */
export async function fetchAuthors(
  supabase: SupabaseClient,
  keys: string[],
): Promise<AuthorCount[]> {
  const found = await Promise.all(
    keys.map(async (key) => {
      const { data } = await supabase
        .from("author_map_count")
        .select("key, name, maps")
        .eq("key", key)
        .maybeSingle();

      return data as AuthorCount | null;
    }),
  );

  return found.filter((author): author is AuthorCount => author !== null);
}

/** One spelling of an author, out of `public.author_spelling`. */
export interface AuthorSpelling {
  key: string;
  raw: string;
  credits: number;
}

/** Every spelling credited under the given keys, read one key at a time for
 *  the reason {@link fetchAuthors} gives. */
export async function fetchSpellings(
  supabase: SupabaseClient,
  keys: string[],
): Promise<AuthorSpelling[]> {
  const found = await Promise.all(
    keys.map(async (key) => {
      const { data } = await supabase
        .from("author_spelling")
        .select("key, raw, credits")
        .eq("key", key);

      return (data ?? []) as unknown as AuthorSpelling[];
    }),
  );

  return found.flat();
}

/**
 * The names the merge form offers, each spelling once, the most credited
 * first.
 *
 * The same spelling can sit under two keys, so the credits are added up before
 * ordering. The spelling breaks ties so the order is the same on every request.
 */
export function nameChoices(spellings: AuthorSpelling[]): string[] {
  const credits = new Map<string, number>();
  for (const spelling of spellings) {
    credits.set(spelling.raw, (credits.get(spelling.raw) ?? 0) + spelling.credits);
  }

  return [...credits.entries()]
    .sort(([a, aCredits], [b, bCredits]) => bCredits - aCredits || (a < b ? -1 : a > b ? 1 : 0))
    .map(([raw]) => raw);
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

/**
 * Recorded merges grouped under the person they point at, so the page can list
 * every key merged into one author together.
 */
export function aliasesByTarget(aliases: AuthorAlias[]): Map<string, AuthorAlias[]> {
  const grouped = new Map<string, AuthorAlias[]>();
  for (const alias of [...aliases].sort((a, b) => (a.toKey < b.toKey ? -1 : a.toKey > b.toKey ? 1 : 0))) {
    grouped.set(alias.toKey, [...(grouped.get(alias.toKey) ?? []), alias]);
  }

  return grouped;
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
 * What a merge came to.
 *
 * `chained` is its own answer rather than a refusal, because it is the one a
 * maintainer needs told: the key they kept is an alias, not a person.
 */
export type MergeOutcome = "merged" | "chained" | "refused";

const OUTCOMES: readonly MergeOutcome[] = ["merged", "chained", "refused"];

/**
 * Merge every key in `keys` into `into`, and name the person if a name is
 * given.
 *
 * One call to `public.merge_author_keys`, so the whole merge is one
 * transaction and the one hop rule is kept in one place. The migration says
 * what it refuses and why. The keys are passed as the list showed them and
 * are not folded again, because `public.author_key` is not idempotent on every
 * input.
 */
export async function mergeAuthorKeys(
  supabase: SupabaseClient,
  into: string,
  keys: string[],
  name: string,
  note: string,
): Promise<MergeOutcome> {
  const { data, error } = await supabase.rpc("merge_author_keys", {
    p_into: into,
    p_keys: keys,
    p_name: name.trim() === "" ? null : name.trim(),
    p_note: note.trim() === "" ? null : note.trim(),
  });

  if (error) return "refused";

  return OUTCOMES.includes(data as MergeOutcome) ? (data as MergeOutcome) : "refused";
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
