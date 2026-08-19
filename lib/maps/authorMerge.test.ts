import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { markChains, mergeAuthorKeys, unmergeAuthorKey } from "./authorMerge";

/**
 * Merging two author keys that are one person (issue #193).
 *
 * The claim worth proving is the one hop rule, because breaking it is silent.
 * `public.resolved_author_key` looks up once and stops, so a merge into a key
 * that is itself merged files those maps under a key nothing counts under: the
 * alias row looks right, the listing shows nothing, and there is no error
 * anywhere. Both directions make a chain and both are refused here, since this
 * form is the only place one can be created.
 *
 * The rest is that nothing normalises a key in TypeScript.
 * `20260818110000_author_keys.sql` argues that at length and names the failure a
 * second copy causes, so the fold is a call and the test proves it is the call
 * that decides what gets written.
 */

interface Recorded {
  from_key: string;
  to_key: string;
  note: string | null;
}

/**
 * Enough of PostgREST for what a merge does: two folds through
 * `public.author_key`, two lookups against the alias table and one upsert.
 *
 * The fold is deliberately not lower casing here. It strips a leading `x` so a
 * test can tell the database's answer apart from the string that was typed, and
 * a merge recorded under the typed spelling rather than the folded one would
 * fail these tests rather than pass them quietly.
 */
function fakeSupabase(aliases: Recorded[]): {
  supabase: SupabaseClient;
  written: () => Recorded[];
} {
  const written: Recorded[] = [];

  const query = () => {
    const filters: Record<string, string> = {};
    const builder = {
      select: () => builder,
      eq: (column: string, value: string) => {
        filters[column] = value;
        return builder;
      },
      limit: () => builder,
      maybeSingle: () =>
        Promise.resolve({
          data:
            aliases.find((alias) =>
              Object.entries(filters).every(
                ([column, value]) => alias[column as keyof Recorded] === value,
              ),
            ) ?? null,
          error: null,
        }),
      upsert: (row: Recorded) => {
        written.push(row);
        return Promise.resolve({ error: null });
      },
      delete: () => builder,
      then: (resolve: (value: { error: null }) => unknown) => resolve({ error: null }),
    };

    return builder;
  };

  return {
    supabase: {
      from: query,
      rpc: async (_name: string, args: { credit: string }) => ({
        data: args.credit.trim().toLowerCase().replace(/^x/, ""),
        error: null,
      }),
    } as unknown as SupabaseClient,
    written: () => written,
  };
}

test("both keys are folded by the database before anything is written", async () => {
  const { supabase, written } = fakeSupabase([]);

  expect(await mergeAuthorKeys(supabase, " XBherith ", "XBeherith", "A typo")).toBe("merged");
  expect(written()).toEqual([{ from_key: "bherith", to_key: "beherith", note: "A typo" }]);
});

/** The column allows a note and the schema says why: it is the part nobody
 *  remembers a year later. An empty box is no note rather than an empty one. */
test("a merge with nothing written about it records no note", async () => {
  const { supabase, written } = fakeSupabase([]);

  await mergeAuthorKeys(supabase, "bherith", "beherith", "   ");

  expect(written()[0].note).toBeNull();
});

/**
 * The mistake the schema wants noticed rather than followed. Rewriting the
 * target to wherever the existing alias points would record a merge nobody
 * asked for and never tell the maintainer that the key they named is not a
 * person.
 */
test("merging into a key that is itself merged is refused", async () => {
  const { supabase, written } = fakeSupabase([
    { from_key: "beherith", to_key: "beherith the mapper", note: null },
  ]);

  expect(await mergeAuthorKeys(supabase, "bherith", "beherith", "")).toBe("chained");
  expect(written()).toEqual([]);
});

/**
 * The same chain seen from the other end, and the one that arrives by accident
 * weeks after the first merge, when nobody is thinking about aliases at all.
 */
test("merging away a key other aliases already point at is refused too", async () => {
  const { supabase, written } = fakeSupabase([
    { from_key: "bherith", to_key: "beherith", note: null },
  ]);

  expect(await mergeAuthorKeys(supabase, "beherith", "beherith the mapper", "")).toBe("chained");
  expect(written()).toEqual([]);
});

test("a key cannot be merged into itself, however it was spelled", async () => {
  const { supabase, written } = fakeSupabase([]);

  expect(await mergeAuthorKeys(supabase, "Beherith", "beherith", "")).toBe("refused");
  expect(written()).toEqual([]);
});

/** `[BAR]` with no name behind it folds to nothing. An alias from or to an
 *  empty key would match the credits that keyed to nothing, which is none of
 *  them, forever. */
test("a key that folds to nothing is not a person", async () => {
  const { supabase, written } = fakeSupabase([]);

  expect(await mergeAuthorKeys(supabase, "", "beherith", "")).toBe("refused");
  expect(await mergeAuthorKeys(supabase, "bherith", "  ", "")).toBe("refused");
  expect(written()).toEqual([]);
});

test("withdrawing a merge says whether it went", async () => {
  const { supabase } = fakeSupabase([]);

  expect(await unmergeAuthorKey(supabase, "bherith")).toBe(true);
});

/**
 * A chain cannot be recorded through the form, so a row that is one was written
 * before the check existed or by hand. The page marks it, because a reader
 * following it lands on a key nothing counts under and nothing about the row
 * itself says so.
 */
test("an alias whose target is itself merged is marked", () => {
  const marked = markChains([
    { from_key: "bherith", to_key: "beherith", note: null, set_at: "2026-08-19T10:00:00Z" },
    { from_key: "beherith", to_key: "beherith the mapper", note: null, set_at: "2026-08-19T11:00:00Z" },
  ]);

  expect(marked.map((alias) => [alias.fromKey, alias.chained])).toEqual([
    ["bherith", true],
    ["beherith", false],
  ]);
});
