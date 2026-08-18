import { expect, test } from "bun:test";
import { POST } from "@/app/api/v1/maps/lookup/route";
import catalog from "@/lib/maps/vendor/map-catalog.json";
import {
  buildMapLookupBody,
  MAP_LOOKUP_FORMAT,
  MAP_LOOKUP_MAX_NAMES,
  MAP_LOOKUP_VERSION,
  type MapFacts,
  parseMapLookupBody,
} from "./mapLookup";

const COMET = "Comet Catcher Remake 1.8";

function namesOf(body: unknown): string[] {
  const parsed = parseMapLookupBody(body);
  if (!parsed.ok) throw new Error(parsed.error);
  return parsed.names;
}

/** One map as `public.map_facts` hands it over, in the shape the answer carries
 * it. The rules that produce it are proved against real rows in
 * `supabase/tests/map_lookup.test.sql`. */
function facts(overrides: Partial<MapFacts> = {}): MapFacts {
  return {
    slug: "comet-catcher-remake-1-8",
    display_name: "Comet Catcher Remake",
    description: "A remake of an old favourite.",
    authors: [{ key: "beherith", name: "Beherith" }],
    width_elmos: 6144,
    height_elmos: 10240,
    world_height_min: -120.5,
    world_height_max: 890,
    min_wind: 5,
    max_wind: 40,
    tidal_strength: 18,
    void_water: false,
    water_coverage: 0.31,
    tags: ["large", "water map", "windy"],
    points: {
      start: [{ x: 512, z: 512, y: null, meta: null }],
      metal: [{ x: 1024, z: 2048, y: null, meta: { amount: 2, radius: 48 } }],
      geo: [],
    },
    appearance: {},
    ...overrides,
  };
}

function held(...entries: [string, MapFacts][]): Map<string, MapFacts> {
  return new Map(entries);
}

test("a body that is not an object is rejected", () => {
  for (const body of [[], null, "names", 3]) {
    expect(parseMapLookupBody(body).ok).toBe(false);
  }
});

test("an unknown field on the body is rejected rather than ignored", () => {
  expect(parseMapLookupBody({ names: [COMET], since: "yesterday" })).toEqual({
    ok: false,
    error: "Unknown field: since",
    status: 400,
  });
});

test("names has to be an array of strings", () => {
  expect(parseMapLookupBody({ names: COMET })).toEqual({
    ok: false,
    error: "`names` is required and must be an array.",
    status: 400,
  });

  expect(parseMapLookupBody({ names: [{ map_name: COMET }] })).toEqual({
    ok: false,
    error: "names[0] must be a string of 1 to 256 characters.",
    status: 400,
  });
});

test("an empty batch is rejected, since it asks nothing", () => {
  expect(parseMapLookupBody({ names: [] })).toEqual({
    ok: false,
    error: "`names` must not be empty.",
    status: 400,
  });
});

test("a name longer than the column accepts is refused before it reaches the database", () => {
  expect(parseMapLookupBody({ names: ["m".repeat(257)] })).toEqual({
    ok: false,
    error: "names[0] must be a string of 1 to 256 characters.",
    status: 400,
  });

  expect(namesOf({ names: ["m".repeat(256)] })).toHaveLength(1);
});

test("a blank name is refused, naming which one it was", () => {
  expect(parseMapLookupBody({ names: [COMET, "  "] })).toEqual({
    ok: false,
    error: "names[1] must be a string of 1 to 256 characters.",
    status: 400,
  });
});

/**
 * The cap is the vendored catalog's, not a number written twice. A client
 * splits its batches on `caps.lookupNames` and this is what refuses one over
 * it, so the two parting company would have the client making requests it was
 * told to make and the hub refusing them.
 */
test("the cap is the one the vendored catalog names", () => {
  expect(MAP_LOOKUP_MAX_NAMES).toBe(catalog.caps.lookupNames);
  expect(MAP_LOOKUP_MAX_NAMES).toBe(500);
});

test("a batch at the limit is accepted and one over it is refused whole", () => {
  const atLimit = Array.from({ length: MAP_LOOKUP_MAX_NAMES }, (_, index) => `Map ${index}`);

  expect(parseMapLookupBody({ names: atLimit }).ok).toBe(true);

  expect(parseMapLookupBody({ names: [...atLimit, "One More 1.0"] })).toEqual({
    ok: false,
    error: "A batch may carry at most 500 names. That request carried 501. Split it.",
    status: 413,
  });
});

/**
 * The difference from the have check, which refuses a repeat. Nothing is
 * carried here but the name, so a repeat asks one question twice, and a lobby
 * list naming one map on several rows is the ordinary case rather than a client
 * bug.
 */
test("a name may appear twice, and both positions are answered", () => {
  expect(namesOf({ names: [COMET, "Other 1.0", COMET] })).toEqual([
    COMET,
    "Other 1.0",
    COMET,
  ]);

  const body = buildMapLookupBody(
    namesOf({ names: [COMET, "Other 1.0", COMET] }),
    held([COMET, facts()]),
  );

  expect(body.results.map((result) => result.map_name)).toEqual([
    COMET,
    "Other 1.0",
    COMET,
  ]);
  expect(body.results[0].map).not.toBeNull();
  expect(body.results[1].map).toBeNull();
  expect(body.results[2].map).toEqual(body.results[0].map);
});

test("a body carries the format marker, the version and one result per name in request order", () => {
  const body = buildMapLookupBody(
    namesOf({ names: ["Zed 1.0", "Alpha 1.0", "Middle 1.0"] }),
    held(["Alpha 1.0", facts({ slug: "alpha" })]),
  );

  expect(body.format).toBe(MAP_LOOKUP_FORMAT);
  expect(body.format).toBe("coilbox-hub-map-lookup");
  expect(body.version).toBe(MAP_LOOKUP_VERSION);
  expect(body.version).toBe(1);
  expect(body.results.map((result) => result.map_name)).toEqual([
    "Zed 1.0",
    "Alpha 1.0",
    "Middle 1.0",
  ]);
  expect(body.results.map((result) => result.map?.slug ?? null)).toEqual([
    null,
    "alpha",
    null,
  ]);
});

/**
 * A known map answers with its facts whole: the measurements, the tags the view
 * derived and the authors as the hub files them. The result carries the name it
 * was asked under and the map beneath it, and nothing else.
 */
test("a known map answers with its facts, its tags and its resolved authors", () => {
  const body = buildMapLookupBody(namesOf({ names: [COMET] }), held([COMET, facts()]));

  expect(body.results).toEqual([{ map_name: COMET, map: facts() }]);
  expect(body.results[0].map?.tags).toEqual(["large", "water map", "windy"]);
  expect(body.results[0].map?.authors).toEqual([{ key: "beherith", name: "Beherith" }]);
  expect(Object.keys(body.results[0]).sort()).toEqual(["map", "map_name"]);
});

/**
 * The ordinary answer for most names while the catalog fills up. An error would
 * make it look like a fault, and the caller's next move is the fallback it
 * would draw anyway.
 */
test("a name the hub knows nothing about is null rather than an error", () => {
  const body = buildMapLookupBody(namesOf({ names: ["Never Heard Of It 1.0"] }), held());

  expect(body.results).toEqual([{ map_name: "Never Heard Of It 1.0", map: null }]);
});

/**
 * Nothing on the wire says what the hub decided about a map or where its facts
 * came from. `/api/v1/maps/have` answers the only question a client asks about
 * that, and `submitted_by` names an account.
 */
test("no provenance and no submitter reaches the wire", () => {
  const serialised = JSON.stringify(
    buildMapLookupBody(namesOf({ names: [COMET] }), held([COMET, facts()])),
  );

  for (const field of [
    "source_hash",
    "source_archive",
    "catalog_version",
    "facts_digest",
    "submitted_by",
  ]) {
    expect(serialised).not.toContain(field);
  }
});

/**
 * The two route level cases the pure functions cannot answer, and neither needs
 * a database: a batch over the cap is refused before the route builds a client.
 * The token is what is under test. There is no `authenticateBearer` call in this
 * route at all, so a request with none and a request with a made up one reach
 * the same place and get the same answer, and neither is a 401.
 */
test("no token is fine, a token is fine, and 501 names is a 413 either way", async () => {
  const body = JSON.stringify({
    names: Array.from({ length: MAP_LOOKUP_MAX_NAMES + 1 }, (_, index) => `Map ${index}`),
  });

  const anonymous = await POST(
    new Request("http://localhost/api/v1/maps/lookup", { method: "POST", body }),
  );
  const carryingAToken = await POST(
    new Request("http://localhost/api/v1/maps/lookup", {
      method: "POST",
      headers: { Authorization: "Bearer not-a-real-token" },
      body,
    }),
  );

  expect(anonymous.status).toBe(413);
  expect(carryingAToken.status).toBe(413);
  expect(await anonymous.json()).toEqual(await carryingAToken.json());
});
