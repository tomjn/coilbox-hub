import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import type { MapFacts } from "@/lib/api/mapLookup";
import type { AssetLicenceRow } from "@/lib/assets/licence";
import { fetchMapFacts, publishableMaps } from "./lookup";

const COMET = "Comet Catcher Remake 1.8";
const TAKEN_DOWN = "Taken Down 1.0";

function facts(slug: string): MapFacts {
  return {
    slug,
    display_name: null,
    description: null,
    authors: [],
    width_elmos: 6144,
    height_elmos: 10240,
    world_height_min: -120.5,
    world_height_max: 890,
    min_wind: null,
    max_wind: null,
    tidal_strength: null,
    void_water: null,
    water_coverage: null,
    tags: ["large"],
    points: { start: [], metal: [], geo: [] },
    appearance: {},
  };
}

/** A row of the shape `public.asset_licence` stores, with only the fields the
 * decision reads worth setting. */
function licence(overrides: Partial<AssetLicenceRow> = {}): AssetLicenceRow {
  return {
    id: "00000000-0000-0000-0000-000000000000",
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
 * says otherwise. */
const BLANKET = licence({
  all_maps: true,
  redistribute_extracted: "allowed",
  redistribute_rendered: "allowed",
});

function takenDown(mapName: string): AssetLicenceRow {
  return licence({ map_name: mapName });
}

interface Seen {
  rpc: { name: string; names: string[] }[];
  filters: string[];
}

/**
 * A stand in for the secret key client. What is under test here is the licence
 * gate and what the route is handed, so nothing touches Postgres: the facts the
 * function assembles are proved against real rows in
 * `supabase/tests/map_lookup.test.sql`.
 */
function fakeSupabase(
  answer: {
    facts?: { map_name: string; facts: MapFacts }[];
    factsError?: boolean;
    licences?: AssetLicenceRow[];
    licenceError?: boolean;
  },
  seen: Seen = { rpc: [], filters: [] },
): SupabaseClient {
  return {
    rpc: (name: string, args: { p_names: string[] }) => {
      seen.rpc.push({ name, names: args.p_names });
      return Promise.resolve(
        answer.factsError
          ? { data: null, error: { message: "down" } }
          : { data: answer.facts ?? [], error: null },
      );
    },
    from: () => ({
      select: () => ({
        or: (filter: string) => {
          seen.filters.push(filter);
          return Promise.resolve(
            answer.licenceError
              ? { data: null, error: { message: "down" } }
              : { data: answer.licences ?? [], error: null },
          );
        },
      }),
    }),
  } as unknown as SupabaseClient;
}

async function factsFor(
  names: string[],
  answer: Parameters<typeof fakeSupabase>[0],
  seen?: Seen,
): Promise<Map<string, MapFacts>> {
  const lookup = await fetchMapFacts(fakeSupabase(answer, seen), names);
  if (!lookup.ok) throw new Error("the lookup failed");
  return lookup.facts;
}

test("a map the hub holds comes back keyed on the name it was found under", async () => {
  const held = await factsFor([COMET], {
    facts: [{ map_name: COMET, facts: facts("comet") }],
    licences: [BLANKET],
  });

  expect(held.get(COMET)).toEqual(facts("comet"));
});

test("a name the hub holds nothing for is simply absent", async () => {
  const held = await factsFor([COMET, "Never Heard Of It 1.0"], {
    facts: [{ map_name: COMET, facts: facts("comet") }],
    licences: [BLANKET],
  });

  expect(held.has("Never Heard Of It 1.0")).toBe(false);
  expect(held.size).toBe(1);
});

/**
 * The takedown, and the whole reason the gate is here. The row exists and the
 * facts are assembled, and the caller is told the hub has never heard of the
 * map, which is what it is told about the minimap as well.
 */
test("a map denied in asset_licence is withheld even though the hub holds the row", async () => {
  const held = await factsFor([COMET, TAKEN_DOWN], {
    facts: [
      { map_name: COMET, facts: facts("comet") },
      { map_name: TAKEN_DOWN, facts: facts("taken-down") },
    ],
    licences: [BLANKET, takenDown(TAKEN_DOWN)],
  });

  expect(held.has(TAKEN_DOWN)).toBe(false);
  expect(held.has(COMET)).toBe(true);
});

/**
 * The default the maintainer recorded in 20260814170100. Almost every map has
 * no row of its own, so this is the ordinary path and a gate that got it wrong
 * would empty the catalog.
 */
test("a map with no row of its own rides the blanket row", async () => {
  const held = await factsFor([COMET], {
    facts: [{ map_name: COMET, facts: facts("comet") }],
    licences: [BLANKET],
  });

  expect(held.has(COMET)).toBe(true);
});

/**
 * The narrow test, argued in `published`. A row that refuses one class of
 * picture is a decision about pictures. The mapper objected to the hub
 * reposting his minimap, not to it knowing how windy his map is.
 */
test("a map that may still be rendered keeps its facts", async () => {
  const held = await factsFor([COMET], {
    facts: [{ map_name: COMET, facts: facts("comet") }],
    licences: [
      BLANKET,
      // The evidence check refuses a yes with nothing behind it, so a row that
      // allows anything names what the permission rests on.
      licence({ map_name: COMET, redistribute_rendered: "allowed", licence: "CC BY 4.0" }),
    ],
  });

  expect(held.has(COMET)).toBe(true);
});

/**
 * `mayRedistribute` reads a missing row as no, and the catalog inherits that
 * rather than working around it. If the blanket row were ever removed the hub
 * goes quiet instead of publishing on the strength of nobody having decided.
 */
test("with no licence row at all nothing is published", async () => {
  const held = await factsFor([COMET], {
    facts: [{ map_name: COMET, facts: facts("comet") }],
    licences: [],
  });

  expect(held.size).toBe(0);
});

test("a read that fails says so rather than answering that the hub holds nothing", async () => {
  expect(await fetchMapFacts(fakeSupabase({ factsError: true }), [COMET])).toEqual({
    ok: false,
  });

  expect(
    await fetchMapFacts(
      fakeSupabase({
        facts: [{ map_name: COMET, facts: facts("comet") }],
        licenceError: true,
      }),
      [COMET],
    ),
  ).toEqual({ ok: false });
});

/**
 * The gate on its own, which is what a map's own page asks (#190). The page
 * holds no facts to filter, so it needs the answer rather than the side effect
 * of the answer.
 */
test("the gate answers with the names it may publish and nothing else", async () => {
  const gate = await publishableMaps(fakeSupabase({ licences: [BLANKET, takenDown(TAKEN_DOWN)] }), [
    COMET,
    TAKEN_DOWN,
  ]);

  expect(gate).toEqual({ ok: true, names: new Set([COMET]) });
});

test("a gate that could not read the table says so rather than refusing everything", async () => {
  expect(await publishableMaps(fakeSupabase({ licenceError: true }), [COMET])).toEqual({
    ok: false,
  });
});

/** The names go to the function in the body of one call, and to the licence
 * table in the query string, which is what the chunking is for. */
test("the facts are one call and the licences are one request per hundred names", async () => {
  const seen: Seen = { rpc: [], filters: [] };
  const names = Array.from({ length: 250 }, (_, index) => `Map ${index}`);

  await factsFor(names, { licences: [BLANKET] }, seen);

  expect(seen.rpc).toEqual([{ name: "map_facts", names }]);
  expect(seen.filters).toHaveLength(3);
  expect(seen.filters[0].startsWith("all_maps.is.true,")).toBe(true);
  expect(seen.filters[0]).toContain('map_name.eq."Map 0"');
  expect(seen.filters[2]).toContain('map_name.eq."Map 249"');
});
