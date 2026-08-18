import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import type { MapFacts } from "@/lib/api/mapLookup";
import type { AssetLicenceRow } from "@/lib/assets/licence";
import { placeholderBox } from "@/lib/assets/placeholder";
import { loadMapPage, markerPosition } from "./page";

const COMET = "Comet Catcher Remake 1.8";
const SLUG = "comet-catcher-remake-1-8";

/** A 12 x 20, which is the shape every mistake in this file shows up on and the
 *  one a square would hide. */
function facts(overrides: Partial<MapFacts> = {}): MapFacts {
  return {
    slug: SLUG,
    display_name: "Comet Catcher Remake",
    description: null,
    authors: [{ key: "beherith", name: "Beherith" }],
    width_elmos: 6144,
    height_elmos: 10240,
    world_height_min: -120.5,
    world_height_max: 890,
    min_wind: 5,
    max_wind: 25,
    tidal_strength: 18,
    void_water: null,
    water_coverage: null,
    tags: ["large", "windy"],
    points: { start: [], metal: [], geo: [] },
    appearance: {},
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
  item?: Row[];
  asset?: Row[];
  asset_licence?: Row[];
  /** What `public.map_facts` answers, keyed on the name it was found under. */
  facts?: { map_name: string; facts: MapFacts }[];
  /** A licence read the hub could not make, which must not read as a licence
   *  that said no. */
  licenceError?: boolean;
}

/**
 * Answers from a list of rows and matches on the columns rather than
 * re-implementing PostgREST's grammar, the same as `itemPictures.test.ts`. The
 * `or` filters belong to `have.test.ts` and `lookup.test.ts` to prove, and the
 * facts themselves are proved against real rows in
 * `supabase/tests/map_lookup.test.sql`.
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
    rpc: (_name: string, args: { p_names: string[] }) =>
      Promise.resolve({
        data: (catalog.facts ?? []).filter((one) => args.p_names.includes(one.map_name)),
        error: null,
      }),
    from: (table: keyof Catalog) => ({ select: () => build(table) }),
  } as unknown as SupabaseClient;
}

/** The two clients the page is handed. Both are the same fake here, because
 *  which key may read which table is the migrations' business and not this
 *  function's. */
function load(catalog: Catalog) {
  const supabase = fakeSupabase(catalog);
  return loadMapPage(supabase, supabase, SLUG);
}

/** The whole catalog answering yes, which is the ordinary state. */
function held(extra: Catalog = {}): Catalog {
  return {
    map: [{ map_name: COMET, slug: SLUG }],
    facts: [{ map_name: COMET, facts: facts() }],
    asset_licence: [BLANKET as unknown as Row],
    ...extra,
  };
}

test("a slug nothing is stored under has no page", async () => {
  expect(await load({ ...held(), map: [] })).toBeNull();
});

/** The row carries a slug and the catalog answers nothing under its name, which
 *  is a map whose facts have gone rather than a page with a hole in it. */
test("a slug whose map the hub holds no facts for has no page", async () => {
  expect(await load({ ...held(), facts: [] })).toBeNull();
});

/**
 * The takedown, and the reason the page reads through the gate at all. The row
 * is there, the facts assemble, and the visitor is told the hub has never heard
 * of the map, which is what a client is told by the lookup route.
 */
test("a map denied in asset_licence has no page even though the hub holds the row", async () => {
  const denied = held({
    asset_licence: [BLANKET as unknown as Row, licence({ map_name: COMET }) as unknown as Row],
  });

  expect(await load(denied)).toBeNull();
});

test("a licence read that fails withholds the page rather than publishing anyway", async () => {
  expect(await load(held({ licenceError: true }))).toBeNull();
});

test("a map with no licence row at all has no page", async () => {
  expect(await load(held({ asset_licence: [] }))).toBeNull();
});

/**
 * The placeholder's shape, which is the whole reason the size is passed down. A
 * 12 x 20 map with no stored minimap is drawn as a 12 x 20 box, and a page that
 * passed nothing would draw a square and claim the map is one.
 */
test("a map with no stored minimap gets a placeholder at the catalog's own shape", async () => {
  const page = await load(held());
  if (!page) throw new Error("the page was withheld");

  expect(page.picture.from).toBe("placeholder");
  if (page.picture.from !== "placeholder") return;

  expect(page.picture.footprint).toEqual({ width: 12, height: 20 });
  expect(placeholderBox(page.picture.footprint)).toEqual({ width: 60, height: 100 });
});

/** The tags, points and credits are the function's answer, passed on rather than
 *  worked out again. `20260818140000_map_lookup.sql` says why the two of them
 *  that cannot be worked out here are the reason the page reads through it. */
test("the facts the page shows are the ones the catalog answered with", async () => {
  const page = await load(
    held({
      facts: [
        {
          map_name: COMET,
          facts: facts({
            points: {
              start: [
                { x: 512, z: 512, y: null, meta: null },
                { x: 5632, z: 9728, y: null, meta: null },
              ],
              metal: [{ x: 1024, z: 2048, y: null, meta: { amount: 2 } }],
              geo: [],
            },
          }),
        },
      ],
    }),
  );

  expect(page?.mapName).toBe(COMET);
  expect(page?.facts.tags).toEqual(["large", "windy"]);
  expect(page?.facts.authors).toEqual([{ key: "beherith", name: "Beherith" }]);
  expect(page?.facts.points.start).toHaveLength(2);
  expect(page?.facts.points.metal).toHaveLength(1);
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

test("gallery items played on the map come back and others do not", async () => {
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
