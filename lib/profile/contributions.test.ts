import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import type { AssetLicenceRow } from "@/lib/assets/licence";
import { PAGE_SIZE } from "@/lib/gallery/query";
import { loadContributions, parseAccountId } from "./contributions";

const ACCOUNT = "c0000000-0000-0000-0000-000000000001";

type Row = Record<string, unknown>;

interface Tables {
  profile?: Row[];
  map?: Row[];
  asset?: Row[];
  game?: Row[];
  item?: Row[];
  asset_licence?: Row[];
}

interface Call {
  table: string;
  method: string;
  args: unknown[];
}

/** Answers from rows, matching `eq` on the column and `not in` on the name list.
 *  Which rows a role may read is the migrations' business and is proved in
 *  `supabase/tests/contributor_profile.test.sql`, so this fake returns whatever
 *  it is given and the test only checks what the loader asks for. */
function fakeSupabase(tables: Tables, calls: Call[] = []): SupabaseClient {
  const build = (table: keyof Tables) => {
    const eqs: [string, unknown][] = [];
    const excluded: string[] = [];
    let limit = Infinity;
    const record = (method: string, args: unknown[]) => calls.push({ table, method, args });

    const matching = () =>
      ((tables[table] ?? []) as Row[]).filter(
        (row) =>
          eqs.every(([column, value]) => row[column] === value) &&
          !excluded.some((list) => list.includes(String(row.map_name))),
      );
    const answer = () => {
      const all = matching();
      return Promise.resolve({ data: all.slice(0, limit), count: all.length, error: null });
    };

    const builder = {
      eq(column: string, value: unknown) {
        record("eq", [column, value]);
        eqs.push([column, value]);
        return builder;
      },
      not(column: string, operator: string, value: string) {
        record("not", [column, operator, value]);
        excluded.push(value);
        return builder;
      },
      filter(column: string, operator: string, value: string) {
        record("filter", [column, operator, value]);
        return builder;
      },
      or: () => builder,
      order(column: string, options: unknown) {
        record("order", [column, options]);
        return builder;
      },
      limit(n: number) {
        record("limit", [n]);
        limit = n;
        return builder;
      },
      maybeSingle: async () => ({ data: (await answer()).data[0] ?? null, error: null }),
      then: (
        resolve: (value: { data: Row[]; count: number; error: null }) => unknown,
        reject: (reason: unknown) => unknown,
      ) => answer().then(resolve, reject),
    };
    return builder;
  };

  return {
    from: (table: keyof Tables) => ({
      select: (columns: string, options?: unknown) => {
        calls.push({ table, method: "select", args: [columns, options] });
        return build(table);
      },
    }),
  } as unknown as SupabaseClient;
}

const BLANKET = {
  id: "00000000-0000-0000-0000-000000000001",
  game: null,
  map_name: null,
  all_maps: true,
  licence: null,
  licence_url: null,
  notes: null,
  decision: "Maintainer decision",
  decided_at: "2026-08-14T00:00:00Z",
  checked_at: "2026-08-14T00:00:00Z",
  checked_by: "a test",
  redistribute_extracted: "allowed",
  redistribute_rendered: "allowed",
  created_at: "2026-08-14T00:00:00Z",
  updated_at: "2026-08-14T00:00:00Z",
} as unknown as AssetLicenceRow;

const DENIED = {
  ...BLANKET,
  id: "00000000-0000-0000-0000-000000000002",
  all_maps: null,
  map_name: "Zyx Taken Down",
  redistribute_extracted: "denied",
  redistribute_rendered: "denied",
} as unknown as AssetLicenceRow;

function held(extra: Tables = {}): Tables {
  return {
    profile: [{ user_id: ACCOUNT, display_name: "Zyx Contributor" }],
    asset_licence: [BLANKET as unknown as Row],
    ...extra,
  };
}

function load(tables: Tables, calls: Call[] = []) {
  const supabase = fakeSupabase(tables, calls);
  return loadContributions(supabase, supabase, ACCOUNT);
}

test("an id that is not a uuid is not an account", () => {
  expect(parseAccountId("not-a-uuid")).toBeNull();
  expect(parseAccountId("")).toBeNull();
  expect(parseAccountId(`${ACCOUNT}x`)).toBeNull();
  expect(parseAccountId("c0000000-0000-0000-0000-00000000000g")).toBeNull();
});

test("a uuid is accepted and lower cased", () => {
  expect(parseAccountId(ACCOUNT.toUpperCase())).toBe(ACCOUNT);
});

test("an account with no profile row has no profile page", async () => {
  expect(await load({ ...held(), profile: [] })).toBeNull();
});

test("an account that has contributed nothing is a profile of zeros", async () => {
  expect(await load(held())).toEqual({
    name: "Zyx Contributor",
    maps: { count: 0, recent: [] },
    pictures: { count: 0 },
    games: { count: 0, recent: [] },
    items: { count: 0, recent: [] },
  });
});

test("each kind of contribution is counted for this account and nobody else", async () => {
  const other = "c0000000-0000-0000-0000-000000000002";
  const profile = await load(
    held({
      map: [
        { map_name: "Zyx One", slug: "zyx-one", display_name: "Zyx One Remake", submitted_by: ACCOUNT },
        { map_name: "Zyx Two", slug: "zyx-two", display_name: null, submitted_by: ACCOUNT },
        { map_name: "Other", slug: "other", display_name: null, submitted_by: other },
      ],
      asset: [
        { uploaded_by: ACCOUNT },
        { uploaded_by: ACCOUNT },
        { uploaded_by: ACCOUNT },
        { uploaded_by: other },
      ],
      game: [
        { shortname: "ZYX", display_name: "Zyx Game", submitted_by: ACCOUNT },
        { shortname: "OTHER", display_name: null, submitted_by: other },
      ],
      item: [
        { id: "i1", title: "Zyx item", author_id: ACCOUNT },
        { id: "i2", title: "Other item", author_id: other },
      ],
    }),
  );

  expect(profile?.maps.count).toBe(2);
  expect(profile?.maps.recent).toEqual([
    { slug: "zyx-one", label: "Zyx One Remake" },
    { slug: "zyx-two", label: "Zyx Two" },
  ]);
  expect(profile?.pictures.count).toBe(3);
  expect(profile?.games).toEqual({
    count: 1,
    recent: [{ shortname: "ZYX", label: "Zyx Game" }],
  });
  expect(profile?.items).toEqual({
    count: 1,
    recent: [{ id: "i1", title: "Zyx item" }],
  });
});

test("a game with no display name is listed by its short name", async () => {
  const profile = await load(
    held({ game: [{ shortname: "ZYX", display_name: null, submitted_by: ACCOUNT }] }),
  );
  expect(profile?.games.recent).toEqual([{ shortname: "ZYX", label: "ZYX" }]);
});

test("a map the licence gate refuses is neither counted nor listed", async () => {
  const profile = await load(
    held({
      asset_licence: [BLANKET as unknown as Row, DENIED as unknown as Row],
      map: [
        { map_name: "Zyx Fine", slug: "zyx-fine", display_name: null, submitted_by: ACCOUNT },
        { map_name: "Zyx Taken Down", slug: "zyx-taken-down", display_name: null, submitted_by: ACCOUNT },
      ],
    }),
  );
  expect(profile?.maps.count).toBe(1);
  expect(profile?.maps.recent).toEqual([{ slug: "zyx-fine", label: "Zyx Fine" }]);
});

test("an unreadable licence gate fails the page rather than listing every map", async () => {
  const supabase = {
    from: (table: string) => ({
      select: () => {
        const answer =
          table === "asset_licence"
            ? { data: null, count: null, error: { message: "down" } }
            : { data: [{ user_id: ACCOUNT, display_name: "Zyx Contributor" }], count: 1, error: null };
        const builder: Record<string, unknown> = {
          eq: () => builder,
          or: () => builder,
          maybeSingle: async () => ({ data: answer.data?.[0] ?? null, error: null }),
          then: (resolve: (value: unknown) => unknown) => Promise.resolve(answer).then(resolve),
        };
        return builder;
      },
    }),
  } as unknown as SupabaseClient;

  await expect(loadContributions(supabase, supabase, ACCOUNT)).rejects.toThrow("licence gate");
});

test("recent lists are newest first and stop at the gallery page size", async () => {
  const calls: Call[] = [];
  await load(held(), calls);

  for (const table of ["map", "game", "item"]) {
    const own = calls.filter((call) => call.table === table);
    expect(own.find((call) => call.method === "order")?.args).toEqual([
      "created_at",
      { ascending: false },
    ]);
    expect(own.find((call) => call.method === "limit")?.args).toEqual([PAGE_SIZE]);
  }
});

test("pictures are counted without fetching rows", async () => {
  const calls: Call[] = [];
  await load(held(), calls);

  const select = calls.find((call) => call.table === "asset" && call.method === "select");
  expect(select?.args[1]).toEqual({ count: "exact", head: true });
});
