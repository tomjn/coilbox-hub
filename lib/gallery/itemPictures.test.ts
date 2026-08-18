import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { BLOB_TIER_BASE } from "@/lib/assets/blob";
import { DEFAULT_ASSET_CDN_BASE } from "@/lib/assets/cdn";
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
    { kind: "challenge", game_key: "bar", map_name: null, container: {} },
  );

  expect(queries).toHaveLength(0);
  expect(pictures).toEqual({ map: null, units: new Map(), packMaps: new Map() });
});

test("the map and every unit are asked for together", async () => {
  const queries: string[] = [];
  await itemPictures(
    fakeSupabase([], queries),
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
    blueprint(["armsolar"]),
  );

  expect(units.size).toBe(0);
});

test("a map the hub holds a minimap of is served it", async () => {
  const { map } = await itemPictures(
    fakeSupabase([mapRow()]),
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
  // Nothing left knows how big a map is, so the placeholder draws a square
  // rather than the map's own proportions.
  const { map } = await itemPictures(fakeSupabase([]), {
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
