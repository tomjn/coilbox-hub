import type { SupabaseClient } from "@supabase/supabase-js";
import { operand } from "@/lib/assets/have";

/**
 * Who made a map, as a map's own page has to show them (#190).
 *
 * `public.map_facts` already answers this for the lookup route, and this is the
 * same answer read a different way. The function is granted to `service_role`
 * alone, because the route needs the licence gate beside it, and a page reading
 * its four tables with the publishable key would gain nothing from the secret
 * one. So the credits are read here, and the two rules the SQL applies are
 * applied here too rather than replaced with something simpler.
 *
 * ## The two rules
 *
 * A credit counts under the key a maintainer has merged it into, not the key it
 * was filed under. `public.map_author.key` is resolved when the submission is
 * written, so a merge recorded afterwards is not in the column, and a page that
 * trusted it would go on splitting one mapper across two links until every map
 * they made was submitted again. `public.resolved_author_key` is the hop and it
 * is called rather than copied, which is what `20260818110000_author_keys.sql`
 * asks of every reader.
 *
 * The name shown is the most common spelling among that author's credits, ties
 * broken by the spelling itself so the answer is settled rather than whichever
 * row came back first. It is not this archive's spelling. An archive crediting
 * `[BAR]Beherith` names the same person as one crediting `Beherith`, and showing
 * whichever this map happened to carry would give one mapper a different name on
 * every map of theirs. That is #183's rule for an author's own page and
 * `public.map_facts`'s rule for the lookup, and this page agreeing with both is
 * the point: a name in the client and a name on the hub have to be one name.
 *
 * The tie break compares spellings by code unit where the SQL compares them by
 * the database's collation. The two orders differ on accents and punctuation, so
 * a mapper with two equally common spellings that differ that way could be shown
 * the other one here. It is a tie between two spellings of one person's name,
 * which is the smallest disagreement in this file worth having.
 */

/** One person on a map: the key everything about them is addressed by, and the
 *  spelling to print. */
export interface MapAuthor {
  key: string;
  name: string;
}

interface CreditRow {
  raw: string;
  key: string;
  credit_index: number;
}

/** A filter naming one key, quoted and escaped through the one rule in
 *  `lib/assets/have.ts`. An author key is normalised but not punctuation free,
 *  so a bare comma in one would end the filter early and lose every spelling
 *  after it. */
function keyFilter(column: string, key: string): string {
  return `${column}.eq.${operand(key)}`;
}

/**
 * What each of these keys resolves to, one call per key.
 *
 * A key with no alias comes back unchanged, which is the function's own answer
 * and is why nothing here branches on whether a merge exists. A call that fails
 * falls back to the key itself, so a maintainer's merge is missed rather than
 * the credit being dropped: a page showing an unmerged name is worse than a page
 * showing no author at all only if you would rather show nothing.
 */
async function resolveKeys(
  supabase: SupabaseClient,
  keys: string[],
): Promise<Map<string, string>> {
  const resolved = new Map<string, string>();

  const answers = await Promise.all(
    keys.map((key) => supabase.rpc("resolved_author_key", { credit_key: key })),
  );

  for (const [index, { data, error }] of answers.entries()) {
    const key = keys[index];
    resolved.set(key, error || typeof data !== "string" ? key : data);
  }

  return resolved;
}

/**
 * Every spelling in the catalog that counts towards one of these keys, with how
 * many credits it appears on.
 *
 * The candidates are read before they are counted, because PostgREST cannot
 * filter on a function of a column: `where resolved_author_key(key) in (...)` is
 * a thing the SQL can write and a request cannot. So the rows that could resolve
 * into these keys are gathered by name - the keys themselves, and the keys a
 * maintainer has merged into them - and every one of them is then put through
 * `public.resolved_author_key` anyway. `public.author_alias` is read to widen
 * the net and never to decide a key, so what a merge means stays the function's
 * answer and not this file's.
 *
 * The alternative is the whole of `public.map_author`, which is what the SQL
 * scans. It is one cheap scan inside the database and a few thousand rows over
 * the wire out of it, on every view of every map page.
 */
async function spellings(
  supabase: SupabaseClient,
  keys: string[],
): Promise<Map<string, Map<string, number>>> {
  // Together, because neither depends on the other and a page's authors are not
  // worth a round trip each.
  const [{ data: aliasRows }, under] = await Promise.all([
    supabase
      .from("author_alias")
      .select("from_key, to_key")
      .or(keys.map((key) => keyFilter("to_key", key)).join(",")),
    // Which key each of these counts under, which is not always itself: a key a
    // maintainer has since merged onward resolves away, and its credits are
    // somebody else's.
    resolveKeys(supabase, keys),
  ]);

  // The merged keys need no call of their own. An alias row says outright what
  // its `from_key` resolves to, and asking the database to repeat it would be a
  // call per merge to learn what the row already carried.
  for (const alias of (aliasRows ?? []) as { from_key: string; to_key: string }[]) {
    under.set(alias.from_key, alias.to_key);
  }

  const { data: rows } = await supabase
    .from("map_author")
    .select("raw, key")
    .or([...under.keys()].map((key) => keyFilter("key", key)).join(","));

  const counts = new Map<string, Map<string, number>>();
  for (const row of (rows ?? []) as { raw: string; key: string }[]) {
    const key = under.get(row.key);
    if (!key || !keys.includes(key)) continue;

    const forKey = counts.get(key) ?? new Map<string, number>();
    forKey.set(row.raw, (forKey.get(row.raw) ?? 0) + 1);
    counts.set(key, forKey);
  }

  return counts;
}

/** The spelling to print for one key: the most credited, then the lowest. */
function popular(counts: Map<string, number> | undefined): string | null {
  let best: string | null = null;
  let bestCount = 0;

  for (const [raw, count] of counts ?? []) {
    if (count > bestCount || (count === bestCount && best !== null && raw < best)) {
      best = raw;
      bestCount = count;
    }
  }

  return best;
}

/**
 * The people credited on one map, in the order the archive credited them.
 *
 * Two credits on one map that resolve to one key are one person, which is what
 * an archive crediting `Beherith` and `[BAR]Beherith` in the same string comes
 * to, and listing them twice would put the same mapper on the page twice. The
 * earliest credit carries the order, because the order is the archive's and is
 * not the hub's to rearrange.
 *
 * Wants a session or anonymous client. Everything read here is granted to
 * `anon`, and the licence gate that decides whether the page exists at all is
 * the page's to ask with the key that can ask it.
 */
export async function mapAuthors(
  supabase: SupabaseClient,
  mapId: string,
): Promise<MapAuthor[]> {
  const { data } = await supabase
    .from("map_author")
    .select("raw, key, credit_index")
    .eq("map_id", mapId)
    .order("credit_index", { ascending: true });

  const credits = (data ?? []) as CreditRow[];
  if (credits.length === 0) return [];

  const resolved = await resolveKeys(supabase, [...new Set(credits.map((c) => c.key))]);
  const keys = [...new Set(credits.map((c) => resolved.get(c.key) ?? c.key))];
  const counts = await spellings(supabase, keys);

  const authors: MapAuthor[] = [];
  const seen = new Set<string>();

  for (const credit of credits) {
    const key = resolved.get(credit.key) ?? credit.key;
    if (seen.has(key)) continue;
    seen.add(key);

    // The credit's own spelling when the count came back empty, which is a
    // failed read rather than a real answer: every key here has at least one
    // spelling, since the credit being answered is itself one of the rows
    // counted.
    authors.push({ key, name: popular(counts.get(key)) ?? credit.raw });
  }

  return authors;
}
