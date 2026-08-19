import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import type { MapFacts } from "@/lib/api/mapLookup";
import { BLOB_TIER_BASE } from "@/lib/assets/blob";
import { DEFAULT_ASSET_CDN_BASE } from "@/lib/assets/cdn";
import type { AssetLicenceRow } from "@/lib/assets/licence";
import {
  blueprintUnitIdentities,
  itemPictures,
  packMapIdentities,
  type PicturedItem,
} from "./itemPictures";

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

/** Answers from a list of rows and records nothing else. The identity filter is
 *  `have.test.ts`'s to prove and `resolve.test.ts`'s to exercise, so this one
 *  matches on the columns rather than re-implementing PostgREST's grammar. */
function fakeSupabase(rows: Row[], queries: string[] = []): SupabaseClient {
  const from = () => ({
    select: () => {
      const filters: [string, string][] = [];
      const builder = {
        eq(column: string, value: string) {
          filters.push([column, value]);
          return builder;
        },
        or(filter: string) {
          queries.push(filter);
          return Promise.resolve({
            data: rows.filter((row) =>
              filters.every(([column, value]) => row[column as keyof Row] === value),
            ),
            error: null,
          });
        },
      };
      return builder;
    },
  });

  return { from } as unknown as SupabaseClient;
}

/** The row 20260814170100 inserts: every map, allowed, unless a row of its own
 *  says otherwise. `page.test.ts` builds the same one for the same gate. */
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
} satisfies AssetLicenceRow;

/** The takedown shape: a row of the map's own permitting nothing at all. */
const DENIED = {
  ...BLANKET,
  id: "00000000-0000-0000-0000-000000000002",
  all_maps: null,
  redistribute_extracted: "denied",
  redistribute_rendered: "denied",
} satisfies AssetLicenceRow;

/** A 12 x 20, which is the shape a square hides every mistake on. */
function facts(overrides: Partial<MapFacts> = {}): MapFacts {
  return {
    slug: "some-custom-map-1-0",
    display_name: null,
    description: null,
    authors: [],
    width_elmos: 6144,
    height_elmos: 10240,
    world_height_min: -100,
    world_height_max: 800,
    min_wind: null,
    max_wind: null,
    tidal_strength: null,
    void_water: null,
    water_coverage: null,
    tags: [],
    points: { start: [], metal: [], geo: [] },
    appearance: {},
    ...overrides,
  };
}

/**
 * The catalog half, which is `public.map_facts` behind `fetchMapFacts` and the
 * licence gate over it. The same fake shape `lib/maps/page.test.ts` uses, since
 * the gate is the same one and this only has to answer for the names asked.
 */
function fakeAdmin(
  held: { map_name: string; facts: MapFacts }[],
  licences: AssetLicenceRow[] = [BLANKET],
): SupabaseClient {
  return {
    rpc: (_name: string, args: { p_names: string[] }) =>
      Promise.resolve({
        data: held.filter((one) => args.p_names.includes(one.map_name)),
        error: null,
      }),
    from: () => ({ select: () => ({ or: () => Promise.resolve({ data: licences, error: null }) }) }),
  } as unknown as SupabaseClient;
}

/** The hub holds no catalog row for anything, which is every item page until
 *  clients start submitting. */
const NO_CATALOG = fakeAdmin([]);

/** A map the hub holds no picture of. */
const GLITTERS = "All That Glitters v2.2.3";

const blueprint = (defs: string[]): PicturedItem => ({
  kind: "blueprint",
  game_key: "bar",
  map_name: null,
  container: {
    payload: {
      name: "Opening",
      buildings: defs.map((def, i) => ({
        def,
        offset: { x: i * 64, z: 0 },
        facing: 0,
      })),
      footprints: { armsolar: { x: 4, z: 4 }, armlab: { x: 8, z: 5 } },
    },
  },
});

function unitRow(unitName: string, overrides: Partial<Row> = {}): Row {
  return {
    game: "bar",
    unit_name: unitName,
    map_name: null,
    variant: "buildpic",
    tier: "static",
    path: `units/bar/buildpic/${unitName}.webp`,
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
    map_name: "Some Custom Map 1.0",
    variant: "minimap",
    tier: "blob",
    path: "maps/minimap/abc-Xy9.webp",
    width: 512,
    height: 384,
    moderation: "approved",
    ...overrides,
  };
}

test("a layout asks for one top render per distinct def, whatever case it was typed in", () => {
  // A plan is drawn from above, so it asks for the view from above.
  expect(blueprintUnitIdentities(blueprint(["ArmSolar", "armsolar", "armlab"]))).toEqual([
    { keyedOn: "unit", game: "bar", unitName: "armsolar", variant: "render:top" },
    { keyedOn: "unit", game: "bar", unitName: "armlab", variant: "render:top" },
  ]);
});

test("a layout with no game names units nothing can be looked up by", () => {
  expect(
    blueprintUnitIdentities({ ...blueprint(["armsolar"]), game_key: null }),
  ).toEqual([]);
});

test("only a blueprint has units in it", () => {
  const preset: PicturedItem = {
    kind: "preset",
    game_key: "bar",
    map_name: "All That Glitters v2.2.3",
    container: { payload: { participants: [] } },
  };

  expect(blueprintUnitIdentities(preset)).toEqual([]);
});

test("an item with nothing to look up makes no query at all", async () => {
  const queries: string[] = [];
  const pictures = await itemPictures(
    fakeSupabase([], queries),
    NO_CATALOG,
    { kind: "challenge", game_key: "bar", map_name: null, container: {} },
  );

  expect(queries).toHaveLength(0);
  expect(pictures).toEqual({
    map: null,
    units: new Map(),
    packMaps: new Map(),
    catalog: new Map(),
  });
});

test("the map and every unit are asked for together", async () => {
  const queries: string[] = [];
  await itemPictures(
    fakeSupabase([], queries),
    NO_CATALOG,
    { ...blueprint(["armsolar", "armlab"]), map_name: "Some Custom Map 1.0" },
  );

  expect(queries).toHaveLength(1);
  expect(queries[0]).toContain("Some Custom Map 1.0");
  expect(queries[0]).toContain("armsolar");
  expect(queries[0]).toContain("armlab");
});

test("a unit the hub has a top render of is served it, and one it does not is absent", async () => {
  const { units } = await itemPictures(
    fakeSupabase([
      unitRow("armsolar", {
        variant: "render:top",
        path: "units/bar/render/top/armsolar.webp",
      }),
    ]),
    NO_CATALOG,
    blueprint(["armsolar", "armlab"]),
  );

  expect([...units.keys()]).toEqual(["armsolar"]);
  expect(units.get("armsolar")).toMatchObject({
    from: "static",
    url: `${DEFAULT_ASSET_CDN_BASE}units/bar/render/top/armsolar.webp`,
    substituted: false,
  });
});

test("a unit with no top render keeps the buildpic the plan drew before", async () => {
  // The angle is wrong and the picture is still the best the hub has. Asking
  // for a render the hub does not hold must not lose a building the picture it
  // does hold, so this is what stops the change being a regression.
  const { units } = await itemPictures(
    fakeSupabase([unitRow("armsolar")]),
    NO_CATALOG,
    blueprint(["armsolar"]),
  );

  expect(units.get("armsolar")).toMatchObject({
    url: `${DEFAULT_ASSET_CDN_BASE}units/bar/buildpic/armsolar.webp`,
    substituted: true,
  });
});

test("a pending buildpic is not a picture, so the building keeps its square", async () => {
  const { units } = await itemPictures(
    fakeSupabase([unitRow("armsolar", { moderation: "pending" })]),
    NO_CATALOG,
    blueprint(["armsolar"]),
  );

  expect(units.size).toBe(0);
});

test("a map the hub holds a minimap of is served it", async () => {
  const { map } = await itemPictures(
    fakeSupabase([mapRow()]),
    NO_CATALOG,
    {
      kind: "preset",
      game_key: "bar",
      map_name: "Some Custom Map 1.0",
      container: {},
    },
  );

  expect(map).toEqual({
    from: "blob",
    url: `${BLOB_TIER_BASE}maps/minimap/abc-Xy9.webp`,
    served: { keyedOn: "map", mapName: "Some Custom Map 1.0", variant: "minimap" },
    substituted: false,
    width: 512,
    height: 384,
  });
});

test("a map the hub has no picture of is drawn without a size", async () => {
  // No catalog row, so nothing knows how big the map is and the placeholder
  // draws a square. This is what an item page has done for every map since #180
  // and what it still does for a map the catalog has never been told about.
  const { map } = await itemPictures(fakeSupabase([]), NO_CATALOG, {
    kind: "preset",
    game_key: "bar",
    map_name: GLITTERS,
    container: {},
  });

  expect(map).toEqual({
    from: "placeholder",
    name: GLITTERS,
    keyedOn: "map",
    footprint: null,
  });
});

const pack = (maps: string[]): PicturedItem => ({
  kind: "setup-pack",
  game_key: null,
  // What the row holds for a pack of several maps, which is why the payload is
  // the only place the whole list is.
  map_name: maps.length === 1 ? maps[0] : null,
  container: { payload: { maps, games: [], engineVersion: ".spring" } },
});

test("a pack asks for a minimap of every map it installs, once each", () => {
  expect(
    packMapIdentities(pack(["Some Custom Map 1.0", GLITTERS, "Some Custom Map 1.0"])),
  ).toEqual([
    { keyedOn: "map", mapName: "Some Custom Map 1.0", variant: "minimap" },
    { keyedOn: "map", mapName: GLITTERS, variant: "minimap" },
  ]);
});

test("only a setup pack installs maps of its own", () => {
  expect(
    packMapIdentities({
      ...blueprint(["armsolar"]),
      container: { payload: { maps: ["Some Custom Map 1.0"] } },
    }),
  ).toEqual([]);
});

test("a pack's maps come back in one query, pictured or not", async () => {
  const queries: string[] = [];
  const { packMaps } = await itemPictures(
    fakeSupabase([mapRow()], queries),
    NO_CATALOG,
    pack(["Some Custom Map 1.0", GLITTERS]),
  );

  expect(queries).toHaveLength(1);
  // Every map the pack names has a card, so the one with nothing stored keeps
  // the placeholder rather than dropping out of the list.
  expect(packMaps.get("Some Custom Map 1.0")).toMatchObject({
    from: "blob",
    url: `${BLOB_TIER_BASE}maps/minimap/abc-Xy9.webp`,
  });
  expect(packMaps.get(GLITTERS)).toEqual({
    from: "placeholder",
    name: GLITTERS,
    keyedOn: "map",
    footprint: null,
  });
});

const CUSTOM = "Some Custom Map 1.0";

const preset: PicturedItem = {
  kind: "preset",
  game_key: "bar",
  map_name: CUSTOM,
  container: {},
};

/**
 * The placeholder's shape, which is half of what the catalog read is for. A
 * 12 x 20 map with no stored minimap is drawn as a 12 x 20 box, and an item page
 * that passed the size on wrongly or not at all would draw a square and claim
 * the map is one.
 */
test("a map the catalog knows is drawn at its own shape rather than a square", async () => {
  const { map } = await itemPictures(
    fakeSupabase([]),
    fakeAdmin([{ map_name: CUSTOM, facts: facts() }]),
    preset,
  );

  expect(map).toEqual({
    from: "placeholder",
    name: CUSTOM,
    keyedOn: "map",
    // Squares rather than elmos, which is the unit a footprint is in.
    footprint: { width: 12, height: 20 },
  });
});

/** The facts the caption and the link are made of, keyed on the name the item
 *  spells the map with. */
test("the catalog answers for the item's own map", async () => {
  const { catalog } = await itemPictures(
    fakeSupabase([]),
    fakeAdmin([{ map_name: CUSTOM, facts: facts({ slug: "some-custom-map-1-0" }) }]),
    preset,
  );

  expect(catalog.get(CUSTOM)?.slug).toBe("some-custom-map-1-0");
  expect(catalog.get(CUSTOM)?.width_elmos).toBe(6144);
});

/**
 * The takedown, and the reason the read goes through `fetchMapFacts` rather than
 * straight at `public.map`. #190 settled that a denied map publishes nothing at
 * all, so an item page captioning one off the catalog would publish the same
 * facts through a second door. It comes back looking like a map the hub has
 * never heard of, which is the state the page already renders.
 */
test("a map denied in asset_licence is absent, the same as one the hub never heard of", async () => {
  const { map, catalog } = await itemPictures(
    fakeSupabase([]),
    fakeAdmin([{ map_name: CUSTOM, facts: facts() }], [BLANKET, { ...DENIED, map_name: CUSTOM }]),
    preset,
  );

  expect(catalog.size).toBe(0);
  expect(map).toEqual({
    from: "placeholder",
    name: CUSTOM,
    keyedOn: "map",
    footprint: null,
  });
});

/** A pack's maps go through the same placeholder, so they get the same size. */
test("a pack's maps are sized off the catalog, one call for the lot", async () => {
  const { packMaps, catalog } = await itemPictures(
    fakeSupabase([]),
    fakeAdmin([{ map_name: CUSTOM, facts: facts() }]),
    pack([CUSTOM, GLITTERS]),
  );

  expect(packMaps.get(CUSTOM)).toMatchObject({ footprint: { width: 12, height: 20 } });
  // The one the catalog says nothing about is unchanged: a square, and no facts
  // to caption it with.
  expect(packMaps.get(GLITTERS)).toMatchObject({ footprint: null });
  expect([...catalog.keys()]).toEqual([CUSTOM]);
});

/** A catalog the hub could not read is an item page without a caption, not an
 *  item page that fails. */
test("a catalog read that fails draws the map the way it drew every map before", async () => {
  const broken = {
    rpc: () => Promise.resolve({ data: null, error: { message: "down" } }),
    from: () => ({
      select: () => ({ or: () => Promise.resolve({ data: [BLANKET], error: null }) }),
    }),
  } as unknown as SupabaseClient;

  const { map, catalog } = await itemPictures(fakeSupabase([]), broken, preset);

  expect(catalog.size).toBe(0);
  expect(map).toMatchObject({ from: "placeholder", footprint: null });
});
