import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { mapAuthors } from "./authors";

const MAP = "00000000-0000-0000-0000-0000000000aa";

interface Credit {
  map_id: string;
  raw: string;
  key: string;
  credit_index: number;
}

interface Alias {
  from_key: string;
  to_key: string;
}

/**
 * Answers from a list of rows and matches on the columns rather than
 * re-implementing PostgREST's grammar, the same as `itemPictures.test.ts`.
 *
 * The `or` filter is deliberately not read. It narrows what comes back and
 * nothing more: which key a spelling counts under is decided after the rows
 * arrive, so a fake handing over the whole table has to reach the same answer as
 * the real query. A test that filtered here would be testing the filter.
 */
function fakeSupabase(catalog: { credits: Credit[]; aliases?: Alias[] }): SupabaseClient {
  const rows = (table: string) =>
    table === "author_alias" ? (catalog.aliases ?? []) : catalog.credits;

  return {
    rpc: (_name: string, args: { credit_key: string }) => {
      const alias = (catalog.aliases ?? []).find((a) => a.from_key === args.credit_key);
      return Promise.resolve({ data: alias?.to_key ?? args.credit_key, error: null });
    },
    from: (table: string) => ({
      select: () => {
        const answer = (all: unknown[]) => Promise.resolve({ data: all, error: null });
        const builder = {
          eq: (column: string, value: string) => ({
            order: () =>
              answer(
                rows(table).filter(
                  (row) => (row as unknown as Record<string, unknown>)[column] === value,
                ),
              ),
          }),
          or: (filter: string) =>
            answer(
              table === "author_alias"
                ? (catalog.aliases ?? []).filter((a) => filter.includes(`"${a.to_key}"`))
                : catalog.credits,
            ),
        };
        return builder;
      },
    }),
  } as unknown as SupabaseClient;
}

function credit(raw: string, key: string, index = 0, mapId = MAP): Credit {
  return { map_id: mapId, raw, key, credit_index: index };
}

test("a map's credits come back in the order the archive credited them", async () => {
  const authors = await mapAuthors(
    fakeSupabase({ credits: [credit("Beherith", "beherith", 0), credit("Icexuick", "icexuick", 1)] }),
    MAP,
  );

  expect(authors).toEqual([
    { key: "beherith", name: "Beherith" },
    { key: "icexuick", name: "Icexuick" },
  ]);
});

/** One string crediting a person twice is one person, not two rows on a page. */
test("two credits on one map that resolve to one key are one author", async () => {
  const authors = await mapAuthors(
    fakeSupabase({
      credits: [credit("Beherith", "beherith", 0), credit("[BAR]Beherith", "beherith", 1)],
    }),
    MAP,
  );

  expect(authors).toEqual([{ key: "beherith", name: "Beherith" }]);
});

/**
 * The rule `public.map_facts` applies, so the name in a client and the name on
 * the hub are one name. This map spells it one way and the catalog mostly
 * spells it another, and the catalog wins.
 */
test("the name shown is the most common spelling in the catalog, not this map's", async () => {
  const authors = await mapAuthors(
    fakeSupabase({
      credits: [
        credit("beherith", "beherith", 0),
        credit("Beherith", "beherith", 0, "another-map"),
        credit("Beherith", "beherith", 0, "a-third-map"),
      ],
    }),
    MAP,
  );

  expect(authors).toEqual([{ key: "beherith", name: "Beherith" }]);
});

/**
 * A merge recorded after the map was submitted. The stored key is the old one,
 * so a page trusting the column would link to a key nothing else counts under
 * and show a name the rest of the catalog has stopped using.
 */
test("a merge recorded after submission decides both the key and the name", async () => {
  const authors = await mapAuthors(
    fakeSupabase({
      credits: [
        credit("BeheRith", "beherith old", 0),
        credit("Beherith", "beherith", 0, "another-map"),
        credit("Beherith", "beherith", 0, "a-third-map"),
      ],
      aliases: [{ from_key: "beherith old", to_key: "beherith" }],
    }),
    MAP,
  );

  expect(authors).toEqual([{ key: "beherith", name: "Beherith" }]);
});

test("a map the archive credited nobody for asks the database nothing else", async () => {
  expect(await mapAuthors(fakeSupabase({ credits: [] }), MAP)).toEqual([]);
});
