import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { siteUrl } from "@/lib/site";
import { ASSET_TIERS, type AssetIdentity } from "./asset";
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
  servable,
  substituteIdentities,
} from "./resolve";

const BUILDPIC: AssetIdentity = {
  keyedOn: "unit",
  game: "bar",
  unitName: "armsolar",
  variant: "buildpic",
};

const RENDER: AssetIdentity = { ...BUILDPIC, variant: "render:270" };

const ANGLED: AssetIdentity = { ...BUILDPIC, variant: "render:angled" };
const FRONT: AssetIdentity = { ...BUILDPIC, variant: "render:front" };
const SIDE: AssetIdentity = { ...BUILDPIC, variant: "render:side" };
const TOP: AssetIdentity = { ...BUILDPIC, variant: "render:top" };

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
  world_height_min?: number | null;
  world_height_max?: number | null;
  bytes_missing_at?: string | null;
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
    tier: "bucket",
    path: "maps/minimap/def.webp",
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

/** A held row as the tests below write one. The world height pair is null on
 *  every row but a height overlay, so it is filled in rather than repeated. */
type TestRow = Omit<HeldRow, "world_height_min" | "world_height_max"> &
  Partial<Pick<HeldRow, "world_height_min" | "world_height_max">>;

function heldOf(...rows: [AssetIdentity, TestRow][]): HeldAssets {
  return new Map(
    rows.map(([identity, row]) => [
      identityKey(identity),
      { world_height_min: null, world_height_max: null, ...row },
    ]),
  );
}

test("a static row resolves to the durable tier", () => {
  expect(assetTierUrl("static", "units/bar/buildpic/abc.webp")).toBe(
    `${DEFAULT_ASSET_CDN_BASE}units/bar/buildpic/abc.webp`,
  );
});

test("every tier is a source a caller has to handle, alongside the placeholder", () => {
  for (const tier of ASSET_TIERS) {
    expect(ASSET_SOURCES).toContain(tier);
  }
  expect(ASSET_SOURCES).toContain("placeholder");
});

// The bucket is private, so an approved row in it is served through the hub's
// own route (#334) rather than at a store URL.

test("a bucket row resolves to the hub's staged picture route", () => {
  expect(assetTierUrl("bucket", "units/bar/buildpic/abc.webp")).toBe(
    `${siteUrl()}/assets/staged/units/bar/buildpic/abc.webp`,
  );
});

test("an approved row in the bucket is served through the route", () => {
  const held = heldOf([
    BUILDPIC,
    { tier: "bucket", path: "units/bar/buildpic/abc.webp", width: 256, height: 256, moderation: "approved" },
  ]);

  expect(resolveAsset(BUILDPIC, held)).toEqual({
    from: "bucket",
    url: `${siteUrl()}/assets/staged/units/bar/buildpic/abc.webp`,
    served: BUILDPIC,
    substituted: false,
    width: 256,
    height: 256,
  });
});

test("a pending or rejected row in the bucket draws the placeholder and never names its path", () => {
  for (const moderation of ["pending", "rejected"] as const) {
    const held = heldOf([
      BUILDPIC,
      { tier: "bucket", path: "units/bar/buildpic/abc.webp", width: 256, height: 256, moderation },
    ]);

    const resolved = resolveAsset(BUILDPIC, held, { width: 4, height: 4 });

    expect(resolved.from).toBe("placeholder");
    expect(JSON.stringify(resolved)).not.toContain("abc.webp");
    expect(servable(held, BUILDPIC)).toBeNull();
  }
});

test("a missing render substitutes a buildpic that is in the bucket", () => {
  const held = heldOf([
    BUILDPIC,
    { tier: "bucket", path: "units/bar/buildpic/abc.webp", width: 256, height: 256, moderation: "approved" },
  ]);

  expect(resolveAsset(RENDER, held)).toMatchObject({
    from: "bucket",
    served: BUILDPIC,
    substituted: true,
    url: `${siteUrl()}/assets/staged/units/bar/buildpic/abc.webp`,
  });
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

test("a row not promoted yet is served from the bucket under the same call", () => {
  const held = heldOf([
    MINIMAP,
    { tier: "bucket", path: "maps/minimap/def.webp", width: 512, height: 512, moderation: "approved" },
  ]);

  const resolved = resolveAsset(MINIMAP, held);

  expect(resolved.from).toBe("bucket");
  expect(resolved).toMatchObject({
    url: `${siteUrl()}/assets/staged/maps/minimap/def.webp`,
    substituted: false,
  });
});

// Approved rows only, at every layer.

test("the resolver refuses a pending row even when one reaches the lookup", () => {
  const held = heldOf([
    BUILDPIC,
    { tier: "bucket", path: "units/bar/buildpic/abc.webp", width: 256, height: 256, moderation: "pending" },
  ]);

  const resolved = resolveAsset(BUILDPIC, held);

  expect(resolved.from).toBe("placeholder");
  expect(JSON.stringify(resolved)).not.toContain("abc.webp");
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
    { tier: "bucket", path: "units/bar/buildpic/abc.webp", width: 256, height: 256, moderation: "pending" },
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

// The other direction. A unit whose archive carried renders but no
// buildpic showed the placeholder, which read as "the hub holds nothing" when
// it held four pictures of the thing.

test("a missing buildpic is served a render of the same unit", () => {
  const held = heldOf([
    SIDE,
    { tier: "static", path: "units/bar/render/side.webp", width: 512, height: 341, moderation: "approved" },
  ]);

  expect(resolveAsset(BUILDPIC, held)).toMatchObject({
    from: "static",
    served: SIDE,
    substituted: true,
    width: 512,
    height: 341,
  });
});

test("the angled render is preferred over every other angle", () => {
  const row = (path: string) => ({
    tier: "static" as const,
    path,
    width: 512,
    height: 512,
    moderation: "approved" as const,
  });
  const held = heldOf(
    [TOP, row("units/bar/render/top.webp")],
    [SIDE, row("units/bar/render/side.webp")],
    [ANGLED, row("units/bar/render/angled.webp")],
  );

  expect(resolveAsset(BUILDPIC, held)).toMatchObject({ served: ANGLED, substituted: true });
});

test("a unit's own buildpic still beats any render of it", () => {
  const held = heldOf(
    [
      BUILDPIC,
      { tier: "static", path: "units/bar/buildpic/abc.webp", width: 256, height: 256, moderation: "approved" },
    ],
    [
      ANGLED,
      { tier: "static", path: "units/bar/render/angled.webp", width: 512, height: 512, moderation: "approved" },
    ],
  );

  expect(resolveAsset(BUILDPIC, held)).toMatchObject({ served: BUILDPIC, substituted: false });
});

test("asking for a buildpic asks for every render angle, angled first", () => {
  expect(ladderIdentities([BUILDPIC])).toEqual([BUILDPIC, ANGLED, FRONT, SIDE, TOP]);
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
    ANGLED,
    FRONT,
    SIDE,
    TOP,
    { ...RENDER, variant: "render:90" },
  ]);
});

test("nothing is added for identities that have no substitute", () => {
  expect(ladderIdentities([MINIMAP])).toEqual([MINIMAP]);
});

/** The substitutes are read off the identity that was asked for and never off
 *  each other, so a render does not drag in every angle behind its own
 *  buildpic. */
test("a substitute is not itself asked for a substitute", () => {
  expect(ladderIdentities([RENDER])).not.toContain(ANGLED);
  expect(substituteIdentities(BUILDPIC).flatMap(substituteIdentities)).toEqual([
    BUILDPIC,
    BUILDPIC,
    BUILDPIC,
    BUILDPIC,
  ]);
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
    world_height_min: null,
    world_height_max: null,
  });
  expect(held.get(identityKey(MINIMAP))?.tier).toBe("bucket");
});

/** #336. A row marked as missing its bytes is never served, so a page never
 * draws a broken image. The render falls back to its buildpic, and a minimap
 * with no substitute to the placeholder. */
test("a row whose bytes the store lost is never served, so the fallback shows", async () => {
  const lost = "2026-09-14T12:00:00Z";
  const held = await fetchHeldAssets(
    fakeSupabase([
      unitRow({ variant: "render:270", tier: "bucket", path: "units/bar/render/270/r-Ab1.webp", bytes_missing_at: lost }),
      unitRow(),
      mapRow({ bytes_missing_at: lost }),
    ]),
    [RENDER, MINIMAP],
  );

  const render = resolveAsset(RENDER, held);
  expect(render.from).toBe("static");
  if (render.from !== "placeholder") expect(render.served).toEqual(BUILDPIC);
  expect(resolveAsset(MINIMAP, held).from).toBe("placeholder");
});

/** The select list is the disclosure list on the public path, so it is asserted
 * rather than left to whoever edits it next. `path` is on it because a URL
 * cannot be built without one, and every row that reaches this query is approved
 * and public already. */
test("the query reads only the columns serving needs", async () => {
  const queries: Query[] = [];
  await fetchHeldAssets(fakeSupabase([], queries), [BUILDPIC]);

  expect(queries[0].columns.split(", ").sort()).toEqual([
    "bytes_missing_at",
    "game",
    "height",
    "map_name",
    "moderation",
    "path",
    "tier",
    "unit_name",
    "variant",
    "width",
    "world_height_max",
    "world_height_min",
  ]);
});

/** The one variant the two columns are set on, and the reason they are on the
 *  select list at all. Nothing can decode a height overlay without them. */
test("a height overlay's world range comes back on the row that carries it", async () => {
  const overlay = { keyedOn: "map", mapName: MINIMAP.mapName, variant: "overlay:height" } as const;
  const held = await fetchHeldAssets(
    fakeSupabase([
      mapRow({
        variant: "overlay:height",
        path: "maps/overlay/height/def.webp",
        world_height_min: -120.5,
        world_height_max: 890,
      }),
    ]),
    [overlay],
  );

  expect(servable(held, overlay)).toMatchObject({
    world_height_min: -120.5,
    world_height_max: 890,
  });
});

/** Null on everything else, which is what the table's own check constraint
 *  says, so a caller reading them off a minimap gets nothing rather than a
 *  number that means something about another picture. */
test("a minimap carries no world range", async () => {
  const held = await fetchHeldAssets(fakeSupabase([mapRow()]), [MINIMAP]);

  expect(servable(held, MINIMAP)).toMatchObject({
    world_height_min: null,
    world_height_max: null,
  });
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
