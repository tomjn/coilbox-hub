import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import type { BarMap } from "@/lib/bar/maps";
import { BLOB_TIER_BASE } from "@/lib/assets/blob";
import { DEFAULT_ASSET_CDN_BASE } from "@/lib/assets/cdn";
import { blueprintUnitIdentities, itemPictures, type PicturedItem } from "./itemPictures";

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

const GLITTERS = {
  springName: "All That Glitters v2.2.3",
  displayName: "All That Glitters",
  mapWidth: 16,
  mapHeight: 12,
} as BarMap;

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

test("a layout asks for one buildpic per distinct def, whatever case it was typed in", () => {
  expect(blueprintUnitIdentities(blueprint(["ArmSolar", "armsolar", "armlab"]))).toEqual([
    { keyedOn: "unit", game: "bar", unitName: "armsolar", variant: "buildpic" },
    { keyedOn: "unit", game: "bar", unitName: "armlab", variant: "buildpic" },
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
    null,
  );

  expect(queries).toHaveLength(0);
  expect(pictures).toEqual({ map: null, units: new Map() });
});

test("the map and every unit are asked for together", async () => {
  const queries: string[] = [];
  await itemPictures(
    fakeSupabase([], queries),
    { ...blueprint(["armsolar", "armlab"]), map_name: "Some Custom Map 1.0" },
    null,
  );

  expect(queries).toHaveLength(1);
  expect(queries[0]).toContain("Some Custom Map 1.0");
  expect(queries[0]).toContain("armsolar");
  expect(queries[0]).toContain("armlab");
});

test("a unit the hub has a picture of is served, and one it does not is absent", async () => {
  const { units } = await itemPictures(
    fakeSupabase([unitRow("armsolar")]),
    blueprint(["armsolar", "armlab"]),
    null,
  );

  expect([...units.keys()]).toEqual(["armsolar"]);
  expect(units.get("armsolar")).toMatchObject({
    from: "static",
    url: `${DEFAULT_ASSET_CDN_BASE}units/bar/buildpic/armsolar.webp`,
    substituted: false,
  });
});

test("a pending buildpic is not a picture, so the building keeps its square", async () => {
  const { units } = await itemPictures(
    fakeSupabase([unitRow("armsolar", { moderation: "pending" })]),
    blueprint(["armsolar"]),
    null,
  );

  expect(units.size).toBe(0);
});

test("a map BAR does not list falls back to the hub's own minimap", async () => {
  const { map } = await itemPictures(
    fakeSupabase([mapRow()]),
    {
      kind: "preset",
      game_key: "bar",
      map_name: "Some Custom Map 1.0",
      container: {},
    },
    null,
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

test("a map with no picture anywhere is drawn from the size BAR gives it", async () => {
  const { map } = await itemPictures(
    fakeSupabase([]),
    {
      kind: "preset",
      game_key: "bar",
      map_name: GLITTERS.springName,
      container: {},
    },
    GLITTERS,
  );

  expect(map).toEqual({
    from: "placeholder",
    name: GLITTERS.springName,
    keyedOn: "map",
    footprint: { width: 16, height: 12 },
  });
});

test("a map BAR does not list and the hub has no picture of still draws something", async () => {
  const { map } = await itemPictures(
    fakeSupabase([]),
    {
      kind: "preset",
      game_key: "bar",
      map_name: "Some Custom Map 1.0",
      container: {},
    },
    null,
  );

  expect(map).toMatchObject({ from: "placeholder", footprint: null });
});
