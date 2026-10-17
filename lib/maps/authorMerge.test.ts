import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import {
  aliasesByTarget,
  markChains,
  mergeAuthorKeys,
  nameChoices,
  searchPattern,
  selectedKeys,
  unmergeAuthorKey,
} from "./authorMerge";

/**
 * Merging author keys that are one person (issues #193 and #442).
 *
 * The rules for what a merge writes live in `public.merge_author_keys` and
 * `supabase/tests/author_merge.test.sql` proves them. What is left here is the
 * page's side: reading the ticked keys out of the URL, turning a search into a
 * pattern that matches what was typed, and offering each spelling once.
 */

function fakeRpc(answer: { data: unknown; error: unknown }) {
  const calls: { name: string; args: unknown }[] = [];
  const supabase = {
    rpc: async (name: string, args: unknown) => {
      calls.push({ name, args });
      return answer;
    },
    from: () => ({
      delete: () => ({ eq: async () => ({ error: null }) }),
    }),
  } as unknown as SupabaseClient;

  return { supabase, calls };
}

test("a merge is one call to the database with every key in it", async () => {
  const { supabase, calls } = fakeRpc({ data: "merged", error: null });

  expect(
    await mergeAuthorKeys(supabase, "beherith", ["original from beherith", "bherith"], " Beherith ", "  "),
  ).toBe("merged");
  expect(calls).toEqual([
    {
      name: "merge_author_keys",
      args: {
        p_into: "beherith",
        p_keys: ["original from beherith", "bherith"],
        p_name: "Beherith",
        p_note: null,
      },
    },
  ]);
});

test("a chained merge comes back as chained", async () => {
  const { supabase } = fakeRpc({ data: "chained", error: null });

  expect(await mergeAuthorKeys(supabase, "bherith", ["jools"], "", "")).toBe("chained");
});

test("a database error is a refusal rather than a merge", async () => {
  const { supabase } = fakeRpc({ data: null, error: { message: "boom" } });

  expect(await mergeAuthorKeys(supabase, "beherith", ["bherith"], "", "")).toBe("refused");
});

test("an answer the page does not know is a refusal", async () => {
  const { supabase } = fakeRpc({ data: "something else", error: null });

  expect(await mergeAuthorKeys(supabase, "beherith", ["bherith"], "", "")).toBe("refused");
});

test("withdrawing a merge says whether it went", async () => {
  const { supabase } = fakeRpc({ data: null, error: null });

  expect(await unmergeAuthorKey(supabase, "bherith")).toBe(true);
});

test("ticked keys are read once each, in order, without blanks", () => {
  expect(selectedKeys(["beherith", "  ", "bherith", "beherith"])).toEqual(["beherith", "bherith"]);
  expect(selectedKeys("beherith")).toEqual(["beherith"]);
  expect(selectedKeys(undefined)).toEqual([]);
});

/** A key with an underscore in it is common, `the_yak`, and an unescaped
 *  underscore matches any character. */
test("a search matches what was typed, anywhere in the key", () => {
  expect(searchPattern(" Beherith ")).toBe("%Beherith%");
  expect(searchPattern("the_yak 100%")).toBe("%the\\_yak 100\\%%");
  expect(searchPattern("a\\b")).toBe("%a\\\\b%");
  expect(searchPattern("   ")).toBeNull();
  expect(searchPattern(undefined)).toBeNull();
});

test("each spelling is offered once, the most used first", () => {
  expect(
    nameChoices([
      { key: "beherith", raw: "Beherith", credits: 1 },
      { key: "beherith", raw: "[teh]Beherith (mysterme@gmail.com)", credits: 2 },
      { key: "bherith", raw: "Beherith", credits: 3 },
      { key: "bherith", raw: "bherith", credits: 1 },
    ]),
  ).toEqual(["Beherith", "[teh]Beherith (mysterme@gmail.com)", "bherith"]);
});

test("merges are grouped under the person they point at", () => {
  const grouped = aliasesByTarget(
    markChains([
      { from_key: "bherith", to_key: "beherith", note: null, set_at: "2026-08-19T10:00:00Z" },
      { from_key: "jools2", to_key: "jools", note: null, set_at: "2026-08-19T10:00:00Z" },
      { from_key: "original from beherith", to_key: "beherith", note: null, set_at: "2026-08-19T10:00:00Z" },
    ]),
  );

  expect([...grouped.keys()]).toEqual(["beherith", "jools"]);
  expect(grouped.get("beherith")?.map((alias) => alias.fromKey)).toEqual([
    "bherith",
    "original from beherith",
  ]);
});

/**
 * The merge refuses to make a chain, so a row that is one was written before
 * the check existed or by hand. The page marks it, because a reader following
 * it lands on a key nothing counts under and nothing about the row itself says
 * so.
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
