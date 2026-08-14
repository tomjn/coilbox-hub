import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { ASSET_TIERS, type AssetIdentity } from "./asset";
import { BLOB_TIER_BASE } from "./blob";
import { DEFAULT_ASSET_CDN_BASE } from "./cdn";
import { identityKey } from "./have";
import {
  ASSET_SOURCES,
  assetTierUrl,
  buildpicSubstitute,
  fetchHeldAssets,
  type HeldAssets,
  type HeldRow,
  ladderIdentities,
  resolveAsset,
} from "./resolve";

const BUILDPIC: AssetIdentity = {
  keyedOn: "unit",
  game: "bar",
  unitName: "armsolar",
  variant: "buildpic",
};

const RENDER: AssetIdentity = { ...BUILDPIC, variant: "render:270" };

const MINIMAP: AssetIdentity = {
  keyedOn: "map",
  mapName: "Comet Catcher Remake 1.8",
  variant: "minimap",
};

interface Row {
  game: string | null;
  unit_name: string | null;
  map_name: string | null;
  variant: string;
  tier: string;
  path: string;
  width: number;
  height: number;
  moderation: string;
}

function unitRow(overrides: Partial<Row> = {}): Row {
  return {
    game: "bar",
    unit_name: "armsolar",
    map_name: null,
    variant: "buildpic",
    tier: "static",
    path: "units/bar/buildpic/abc.webp",
    width: 256,
    height: 256,
    moderation: "approved",
    ...overrides,
  };
}

function mapRow(overrides: Partial<Row> = {}): Row {
  return {
    game: null,
    unit_name: null,
    map_name: "Comet Catcher Remake 1.8",
    variant: "minimap",
    tier: "blob",
    path: "maps/minimap/def-Xy9.webp",
    width: 512,
    height: 512,
    moderation: "approved",
    ...overrides,
  };
}

interface Query {
  columns: string;
  filters: [string, string][];
  or: string;
}

/**
 * Honours the `moderation` filter for real, so a test that supplies a pending
 * row is testing the query rather than the fake's manners. The identity filter
 * is recorded rather than applied: `have.test.ts` already proves what it says,
 * and re-implementing PostgREST's grammar here would test the copy.
 */
function fakeSupabase(
  rows: Row[],
  queries: Query[] = [],
  failing = false,
): SupabaseClient {
  const from = () => ({
    select: (columns: string) => {
      const query: Query = { columns, filters: [], or: "" };
      const builder = {
        eq(column: string, value: string) {
          query.filters.push([column, value]);
          return builder;
        },
        or(filter: string) {
          query.or = filter;
          queries.push(query);
          if (failing) return Promise.resolve({ data: null, error: new Error("down") });

          const kept = rows.filter((row) =>
            query.filters.every(([column, value]) => row[column as keyof Row] === value),
          );
          return Promise.resolve({ data: kept, error: null });
        },
      };
      return builder;
    },
  });

  return { from } as unknown as SupabaseClient;
}

function heldOf(...rows: [AssetIdentity, HeldRow][]): HeldAssets {
  return new Map(rows.map(([identity, row]) => [identityKey(identity), row]));
}

test("a blob row resolves to the staging store and a static row to the durable tier", () => {
  expect(assetTierUrl("blob", "units/bar/buildpic/abc-Xy9.webp")).toBe(
    `${BLOB_TIER_BASE}units/bar/buildpic/abc-Xy9.webp`,
  );
  expect(assetTierUrl("static", "units/bar/buildpic/abc.webp")).toBe(
    `${DEFAULT_ASSET_CDN_BASE}units/bar/buildpic/abc.webp`,
  );
});

test("both tiers are sources a caller has to handle, alongside the placeholder", () => {
  for (const tier of ASSET_TIERS) {
    expect(ASSET_SOURCES).toContain(tier);
  }
  expect(ASSET_SOURCES).toContain("placeholder");
});

// The rung that matters today: there are no asset rows anywhere, so this is what
// every caller actually gets until the seed lands.

test("an identity with no row at all draws a placeholder rather than failing", () => {
  const resolved = resolveAsset(BUILDPIC, new Map(), { width: 4, height: 4 });

  expect(resolved).toEqual({
    from: "placeholder",
    name: "armsolar",
    keyedOn: "unit",
    footprint: { width: 4, height: 4 },
  });
});

test("a map with no row and no size still resolves to something renderable", () => {
  expect(resolveAsset(MINIMAP, new Map())).toEqual({
    from: "placeholder",
    name: "Comet Catcher Remake 1.8",
    keyedOn: "map",
    footprint: null,
  });
});

test("a lookup holding somebody else's picture is not this identity's", () => {
  const held = heldOf([
    { ...BUILDPIC, unitName: "armsolar", game: "xta" },
    { tier: "static", path: "units/xta/buildpic/abc.webp", width: 256, height: 256, moderation: "approved" },
  ]);

  expect(resolveAsset(BUILDPIC, held).from).toBe("placeholder");
});

// The tiers.

test("the row's own tier says where the bytes are, and the caller never asks", () => {
  const held = heldOf([
    BUILDPIC,
    { tier: "static", path: "units/bar/buildpic/abc.webp", width: 256, height: 256, moderation: "approved" },
  ]);

  expect(resolveAsset(BUILDPIC, held)).toEqual({
    from: "static",
    url: `${DEFAULT_ASSET_CDN_BASE}units/bar/buildpic/abc.webp`,
    served: BUILDPIC,
    substituted: false,
    width: 256,
    height: 256,
  });
});

test("a row not promoted yet is served from Blob under the same call", () => {
  const held = heldOf([
    MINIMAP,
    { tier: "blob", path: "maps/minimap/def-Xy9.webp", width: 512, height: 512, moderation: "approved" },
  ]);

  const resolved = resolveAsset(MINIMAP, held);

  expect(resolved.from).toBe("blob");
  expect(resolved).toMatchObject({
    url: `${BLOB_TIER_BASE}maps/minimap/def-Xy9.webp`,
    substituted: false,
  });
});

// Approved rows only, at every layer.

test("the resolver refuses a pending row even when one reaches the lookup", () => {
  const held = heldOf([
    BUILDPIC,
    { tier: "blob", path: "units/bar/buildpic/abc-Xy9.webp", width: 256, height: 256, moderation: "pending" },
  ]);

  const resolved = resolveAsset(BUILDPIC, held);

  expect(resolved.from).toBe("placeholder");
  expect(JSON.stringify(resolved)).not.toContain("abc-Xy9");
});

test("a rejected row is no more servable than a pending one", () => {
  const held = heldOf([
    MINIMAP,
    { tier: "static", path: "maps/minimap/def.webp", width: 512, height: 512, moderation: "rejected" },
  ]);

  expect(resolveAsset(MINIMAP, held).from).toBe("placeholder");
});

// The buildpic substitute.

test("a missing render is served the unit's buildpic, and says so", () => {
  const held = heldOf([
    BUILDPIC,
    { tier: "static", path: "units/bar/buildpic/abc.webp", width: 256, height: 256, moderation: "approved" },
  ]);

  expect(resolveAsset(RENDER, held)).toEqual({
    from: "static",
    url: `${DEFAULT_ASSET_CDN_BASE}units/bar/buildpic/abc.webp`,
    served: BUILDPIC,
    substituted: true,
    width: 256,
    height: 256,
  });
});

test("a render the hub actually has is not a substitution", () => {
  const held = heldOf([
    RENDER,
    { tier: "static", path: "units/bar/render/270/ghi.webp", width: 256, height: 192, moderation: "approved" },
  ]);

  expect(resolveAsset(RENDER, held)).toMatchObject({
    served: RENDER,
    substituted: false,
    width: 256,
    height: 192,
  });
});

test("a pending buildpic does not stand in for a missing render either", () => {
  const held = heldOf([
    BUILDPIC,
    { tier: "blob", path: "units/bar/buildpic/abc-Xy9.webp", width: 256, height: 256, moderation: "pending" },
  ]);

  expect(resolveAsset(RENDER, held).from).toBe("placeholder");
});

/** A minimap and an overlay are pictures of different things, not views of one,
 * so there is nothing to fall back to and a substitute would be a lie. */
test("nothing stands in for a map's own variants", () => {
  expect(buildpicSubstitute(MINIMAP)).toBeNull();
  expect(buildpicSubstitute({ ...MINIMAP, variant: "overlay:metal" })).toBeNull();
});

test("a buildpic does not stand in for itself", () => {
  expect(buildpicSubstitute(BUILDPIC)).toBeNull();
});

test("every render angle falls back to the one buildpic for that unit", () => {
  expect(buildpicSubstitute(RENDER)).toEqual(BUILDPIC);
  expect(buildpicSubstitute({ ...RENDER, variant: "render:0" })).toEqual(BUILDPIC);
});

// What a batch has to ask for.

test("asking for a render also asks for the buildpic behind it", () => {
  expect(ladderIdentities([RENDER])).toEqual([RENDER, BUILDPIC]);
});

test("a buildpic asked for twice over is asked for once", () => {
  expect(ladderIdentities([RENDER, BUILDPIC, { ...RENDER, variant: "render:90" }])).toEqual([
    RENDER,
    BUILDPIC,
    { ...RENDER, variant: "render:90" },
  ]);
});

test("nothing is added for identities that have no substitute", () => {
  expect(ladderIdentities([MINIMAP, BUILDPIC])).toEqual([MINIMAP, BUILDPIC]);
});

// The query.

test("the query asks for approved rows and keys the answer by identity", async () => {
  const queries: Query[] = [];
  const held = await fetchHeldAssets(fakeSupabase([unitRow(), mapRow()], queries), [
    BUILDPIC,
    MINIMAP,
  ]);

  expect(queries).toHaveLength(1);
  expect(queries[0].filters).toEqual([["moderation", "approved"]]);
  expect(held.get(identityKey(BUILDPIC))).toEqual({
    tier: "static",
    path: "units/bar/buildpic/abc.webp",
    width: 256,
    height: 256,
    moderation: "approved",
  });
  expect(held.get(identityKey(MINIMAP))?.tier).toBe("blob");
});

/** The select list is the disclosure list on the public path, so it is asserted
 * rather than left to whoever edits it next. `path` is on it because a URL
 * cannot be built without one, and every row that reaches this query is approved
 * and public already. */
test("the query reads only the columns serving needs", async () => {
  const queries: Query[] = [];
  await fetchHeldAssets(fakeSupabase([], queries), [BUILDPIC]);

  expect(queries[0].columns.split(", ").sort()).toEqual([
    "game",
    "height",
    "map_name",
    "moderation",
    "path",
    "tier",
    "unit_name",
    "variant",
    "width",
  ]);
});

test("a pending row never comes back from the query at all", async () => {
  const held = await fetchHeldAssets(fakeSupabase([unitRow({ moderation: "pending" })]), [
    BUILDPIC,
  ]);

  expect(held.size).toBe(0);
  expect(resolveAsset(BUILDPIC, held).from).toBe("placeholder");
});

test("a batch asking for nothing costs no query", async () => {
  const queries: Query[] = [];
  const held = await fetchHeldAssets(fakeSupabase([], queries), []);

  expect(held.size).toBe(0);
  expect(queries).toEqual([]);
});

test("a batch too big for one request line is split, and the answers join up", async () => {
  const queries: Query[] = [];
  const identities: AssetIdentity[] = Array.from({ length: 120 }, (_, index) => ({
    keyedOn: "map",
    mapName: `Map ${index}`,
    variant: "minimap",
  }));

  await fetchHeldAssets(fakeSupabase([], queries), identities);

  expect(queries).toHaveLength(3);
});

/** A lookup that fails means the hub does not know what it holds, and the honest
 * render for that is the placeholder. A 500 would take an item page down over a
 * thumbnail. */
test("a query that errors draws placeholders rather than breaking the page", async () => {
  const held = await fetchHeldAssets(fakeSupabase([unitRow()], [], true), [BUILDPIC]);

  expect(held.size).toBe(0);
  expect(resolveAsset(BUILDPIC, held, { width: 4, height: 4 })).toMatchObject({
    from: "placeholder",
    name: "armsolar",
  });
});
