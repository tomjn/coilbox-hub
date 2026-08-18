import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import type { AssetLicenceRow } from "@/lib/assets/licence";
import { placeholderBox } from "@/lib/assets/placeholder";
import { loadMapPage, type MapRow, markerPosition } from "./page";

const ID = "00000000-0000-0000-0000-0000000000aa";
const COMET = "Comet Catcher Remake 1.8";

/** A 12 x 20 map, which is the shape every mistake in this file shows up on and
 *  the one a square never would. */
function mapRow(overrides: Partial<MapRow> = {}): MapRow {
  return {
    id: ID,
    map_name: COMET,
    slug: "comet-catcher-remake-1-8",
    display_name: "Comet Catcher Remake",
    description: null,
    width_elmos: 6144,
    height_elmos: 10240,
    min_wind: 5,
    max_wind: 25,
    tidal_strength: 18,
    void_water: null,
    ...overrides,
  };
}

function licence(overrides: Partial<AssetLicenceRow> = {}): AssetLicenceRow {
  return {
    id: "00000000-0000-0000-0000-000000000001",
    game: null,
    map_name: null,
    all_maps: null,
    licence: null,
    licence_url: null,
    notes: null,
    decision: "Maintainer decision",
    decided_at: "2026-08-14T00:00:00Z",
    checked_at: "2026-08-14T00:00:00Z",
    checked_by: "a test",
    redistribute_extracted: "denied",
    redistribute_rendered: "denied",
    created_at: "2026-08-14T00:00:00Z",
    updated_at: "2026-08-14T00:00:00Z",
    ...overrides,
  };
}

/** The row 20260814170100 inserts: every map, allowed, unless a row of its own
 *  says otherwise. */
const BLANKET = licence({
  all_maps: true,
  redistribute_extracted: "allowed",
  redistribute_rendered: "allowed",
});

type Row = Record<string, unknown>;

interface Catalog {
  map?: Row[];
  map_listing?: Row[];
  map_point?: Row[];
  map_author?: Row[];
  author_alias?: Row[];
  item?: Row[];
  asset?: Row[];
  asset_licence?: Row[];
  /** A licence read the hub could not make, which must not read as a licence
   *  that said no. */
  licenceError?: boolean;
}

/**
 * Answers from a list of rows and matches on the columns rather than
 * re-implementing PostgREST's grammar, the same as `itemPictures.test.ts`. The
 * `or` filters belong to `have.test.ts` and `lookup.test.ts` to prove.
 */
function fakeSupabase(catalog: Catalog): SupabaseClient {
  const build = (table: keyof Catalog) => {
    const filters: [string, unknown][] = [];
    const rows = () =>
      ((catalog[table] ?? []) as Row[]).filter((row) =>
        filters.every(([column, value]) => row[column] === value),
      );
    const answer = () =>
      catalog.licenceError && table === "asset_licence"
        ? Promise.resolve({ data: null, error: { message: "down" } })
        : Promise.resolve({ data: rows(), error: null });

    const builder = {
      eq(column: string, value: unknown) {
        filters.push([column, value]);
        return builder;
      },
      order: () => builder,
      limit: () => builder,
      or: () => builder,
      maybeSingle: async () => ({ data: (await answer()).data?.[0] ?? null, error: null }),
      then: (
        resolve: (value: { data: Row[] | null; error: unknown }) => unknown,
        reject: (reason: unknown) => unknown,
      ) => answer().then(resolve, reject),
    };

    return builder;
  };

  return {
    rpc: (_name: string, args: { credit_key: string }) =>
      Promise.resolve({ data: args.credit_key, error: null }),
    from: (table: keyof Catalog) => ({ select: () => build(table) }),
  } as unknown as SupabaseClient;
}

/** The two clients the page is handed. Both are the same fake here, because
 *  which key reads which table is the migrations' business and not this
 *  function's. */
function load(catalog: Catalog) {
  const supabase = fakeSupabase(catalog);
  return loadMapPage(supabase, supabase, "comet-catcher-remake-1-8");
}

/** The whole catalog answering yes, which is the ordinary state. */
function held(extra: Catalog = {}): Catalog {
  return {
    map: [mapRow() as unknown as Row],
    asset_licence: [BLANKET as unknown as Row],
    ...extra,
  };
}

test("a slug nothing is stored under has no page", async () => {
  expect(await load({ asset_licence: [BLANKET as unknown as Row] })).toBeNull();
});

/**
 * The takedown, and the reason the page asks the gate at all. The row is there,
 * the facts assemble, and the visitor is told the hub has never heard of the
 * map, which is what the lookup route tells a client.
 */
test("a map denied in asset_licence has no page even though the hub holds the row", async () => {
  expect(
    await load(
      held({ asset_licence: [BLANKET as unknown as Row, licence({ map_name: COMET }) as unknown as Row] }),
    ),
  ).toBeNull();
});

test("a licence read that fails withholds the page rather than publishing anyway", async () => {
  expect(await load(held({ licenceError: true }))).toBeNull();
});

test("a map with no licence row at all has no page", async () => {
  expect(await load({ map: [mapRow() as unknown as Row], asset_licence: [] })).toBeNull();
});

/**
 * The placeholder's shape, which is the whole reason the footprint is passed
 * down. A 12 x 20 map with no stored minimap is drawn as a 12 x 20 box, and a
 * page that passed nothing would draw a square and claim the map is one.
 */
test("a map with no stored minimap gets a placeholder at the catalog's own shape", async () => {
  const page = await load(held());
  if (!page) throw new Error("the page was withheld");

  expect(page.picture.from).toBe("placeholder");
  if (page.picture.from !== "placeholder") return;

  expect(page.picture.footprint).toEqual({ width: 12, height: 20 });
  expect(placeholderBox(page.picture.footprint)).toEqual({ width: 60, height: 100 });
});

test("the points are split by kind and left in the order they were stored", async () => {
  const page = await load(
    held({
      map_point: [
        { map_id: ID, kind: "start", ordinal: 0, x: 512, z: 512 },
        { map_id: ID, kind: "start", ordinal: 1, x: 5632, z: 9728 },
        { map_id: ID, kind: "metal", ordinal: 0, x: 1024, z: 2048 },
      ],
    }),
  );

  expect(page?.spots).toEqual({
    start: [
      { x: 512, z: 512 },
      { x: 5632, z: 9728 },
    ],
    metal: [{ x: 1024, z: 2048 }],
    geo: [],
  });
});

/**
 * The elmo to percentage division, on the shape that shows a mistake. A start
 * position near the far corner of a 12 x 20 lands near the far corner of the
 * figure, and dividing z by the width instead would put it well past the bottom
 * edge.
 */
test("a marker on a map that is not square lands inside the figure", () => {
  const map = { width_elmos: 6144, height_elmos: 10240 };
  const corner = markerPosition({ x: 5632, z: 9728 }, map);

  expect(corner.left).toBeCloseTo(91.7, 1);
  expect(corner.top).toBeCloseTo(95, 1);
  expect(corner.left).toBeLessThan(100);
  expect(corner.top).toBeLessThan(100);
});

/** The two axes are not interchangeable, and a swap is invisible on a square. */
test("x reads across and z reads down", () => {
  const map = { width_elmos: 6144, height_elmos: 10240 };

  expect(markerPosition({ x: 3072, z: 1024 }, map)).toEqual({ left: 50, top: 10 });
});

test("gallery items played on the map come back newest first", async () => {
  const page = await load(
    held({
      item: [
        { id: "1", kind: "preset", title: "A start", map_name: COMET, tags: [] },
        { id: "2", kind: "preset", title: "Elsewhere", map_name: "Another Map 1.0", tags: [] },
      ],
    }),
  );

  expect(page?.played.map((item) => item.id)).toEqual(["1"]);
});

/** Nothing has been published for most maps, and that is ordinary rather than a
 *  fault. The page shows no section for it, which `MapPlayedOn` proves. */
test("a map nothing has been published for has an empty list rather than no answer", async () => {
  const page = await load(held());

  expect(page?.played).toEqual([]);
});

test("the tags come off the listing view rather than being worked out again", async () => {
  const page = await load(held({ map_listing: [{ id: ID, tags: ["large", "windy"] }] }));

  expect(page?.tags).toEqual(["large", "windy"]);
});
