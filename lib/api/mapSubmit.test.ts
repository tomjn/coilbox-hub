import { expect, test } from "bun:test";
import { POST } from "@/app/api/v1/maps/route";
import type { MapEntry } from "@/lib/maps/facts";
import catalog from "@/lib/maps/vendor/map-catalog.json";
import {
  buildMapSubmitBody,
  MAP_SUBMIT_FORMAT,
  MAP_SUBMIT_MAX_BYTES,
  MAP_SUBMIT_MAX_MAPS,
  MAP_SUBMIT_VERSION,
  parseMapSubmitBody,
  type SubmittedEntry,
} from "./mapSubmit";

const MAP = {
  map_name: "Comet Catcher Remake 1.8",
  display_name: "Comet Catcher Remake",
  description: "A remake of the original",
  map_version: "1.8",
  author: "Beherith & Icexuick",
  archive_filename: "comet_catcher_remake_1.8.sd7",
  source_archive: "Comet Catcher Remake 1.8",
  source_hash: "src-comet",
  catalog_version: 3,
  width_elmos: 6144,
  height_elmos: 10240,
  world_height_min: -120.5,
  world_height_max: 890,
  min_wind: 5,
  max_wind: 40,
  tidal_strength: 18,
  void_water: false,
  void_ground: false,
  water_coverage: 0.31,
  appearance: { water: { colour: [0, 0.2, 0.4] } },
  points: {
    start: [{ x: 512, z: 512 }],
    metal: [{ x: 1024, z: 2048, meta: { amount: 2, radius: 48 } }],
    geo: [{ x: 3072, z: 4096, meta: { feature: "geovent" } }],
  },
};

function body(...maps: unknown[]) {
  return { format: MAP_SUBMIT_FORMAT, version: MAP_SUBMIT_VERSION, maps };
}

function entriesOf(value: unknown): SubmittedEntry[] {
  const parsed = parseMapSubmitBody(value);
  if (!parsed.ok) throw new Error(parsed.error);
  return parsed.entries;
}

function entry(overrides: Record<string, unknown> = {}): MapEntry {
  const parsed = entriesOf(body({ ...MAP, ...overrides }))[0];
  if (!parsed.ok) throw new Error(parsed.said);
  return parsed.entry;
}

/** The reason one entry was refused, where the batch itself was fine. */
function said(overrides: Record<string, unknown>): string {
  const parsed = entriesOf(body({ ...MAP, ...overrides }))[0];
  if (parsed.ok) throw new Error("expected a refusal");
  return parsed.said;
}

test("an entry parses into the facts the table holds", () => {
  expect(entry()).toEqual({
    map_name: "Comet Catcher Remake 1.8",
    display_name: "Comet Catcher Remake",
    description: "A remake of the original",
    map_version: "1.8",
    author: "Beherith & Icexuick",
    archive_filename: "comet_catcher_remake_1.8.sd7",
    source_archive: "Comet Catcher Remake 1.8",
    source_hash: "src-comet",
    catalog_version: 3,
    width_elmos: 6144,
    height_elmos: 10240,
    world_height_min: -120.5,
    world_height_max: 890,
    min_wind: 5,
    max_wind: 40,
    tidal_strength: 18,
    void_water: false,
    void_ground: false,
    water_coverage: 0.31,
    appearance: { water: { colour: [0, 0.2, 0.4] } },
    points: {
      start: [{ x: 512, z: 512, y: null, meta: null }],
      metal: [{ x: 1024, z: 2048, y: null, meta: { amount: 2, radius: 48 } }],
      geo: [{ x: 3072, z: 4096, y: null, meta: { feature: "geovent" } }],
    },
  });
});

/**
 * The hub computes all three, so a client that sends one is told rather than
 * quietly overruled. A declared slug takes the URL of a map somebody else
 * submitted, a declared digest reads as unchanged facts forever, and a declared
 * key files a map under whichever author the client fancied.
 */
test("a client cannot declare what the hub works out for itself", () => {
  expect(said({ slug: "comet" })).toBe("Unknown field: slug");
  expect(said({ facts_digest: "d" })).toBe("Unknown field: facts_digest");
  expect(said({ author_key: "beherith" })).toBe("Unknown field: author_key");
});

/** Nothing about what kind of map it is is measured, so nothing asks. The
 * listing view works the tags out from the measurements. */
test("a client sends measurements and not conclusions", () => {
  expect(said({ tags: ["water map"] })).toBe("Unknown field: tags");
});

/**
 * The same strictness `parseMapHaveBody` has, for the same reason: a client that
 * spelled `source_hash` as `sourceHash` and had it ignored would write a row
 * that dedupes against nothing and resubmit its whole corpus on every run.
 */
test("an unknown field is refused rather than ignored", () => {
  expect(said({ sourceHash: "src-comet" })).toBe("Unknown field: sourceHash");
});

/**
 * Where this parts company with the have check. A have check answers a
 * question, so a bad key makes the whole answer wrong. A submission does work,
 * and throwing away forty nine good entries because the fiftieth carries a field
 * the hub does not know leaves a client unable to make progress at all.
 */
test("one malformed entry is refused on its own, and the rest of the batch parses", () => {
  const entries = entriesOf(
    body(
      { ...MAP, map_name: "Alpha 1.0" },
      { ...MAP, map_name: "Beta 1.0", nonsense: true },
      { ...MAP, map_name: "Gamma 1.0" },
    ),
  );

  expect(entries.map((parsed) => parsed.ok)).toEqual([true, false, true]);
  expect(entries[1]).toEqual({
    ok: false,
    mapName: "Beta 1.0",
    said: "Unknown field: nonsense",
  });
});

/** The results are positional, so an entry with no readable name still gets an
 * answer in its own place. */
test("an entry with no usable name is refused under an empty one", () => {
  const entries = entriesOf(body({ ...MAP, map_name: 7 }));

  expect(entries[0]).toEqual({
    ok: false,
    mapName: "",
    said: "`map_name` is required and must be a string of 1 to 256 characters.",
  });
});

test("the envelope on the request has to be one the hub speaks", () => {
  expect(parseMapSubmitBody({ ...body(MAP), format: "coilbox-hub-assets" })).toEqual({
    ok: false,
    error: '`format` must be "coilbox-hub-maps".',
    status: 400,
  });

  expect(parseMapSubmitBody({ ...body(MAP), version: 2 })).toEqual({
    ok: false,
    error: "`version` must be 1. This hub speaks no other.",
    status: 400,
  });
});

test("a body that is not an object, or names an unknown field, is refused whole", () => {
  for (const value of [[], null, "maps", 3]) {
    expect(parseMapSubmitBody(value).ok).toBe(false);
  }

  expect(parseMapSubmitBody({ ...body(MAP), since: "yesterday" })).toEqual({
    ok: false,
    error: "Unknown field: since",
    status: 400,
  });
});

test("maps has to be a non empty array", () => {
  expect(parseMapSubmitBody(body())).toEqual({
    ok: false,
    error: "`maps` must not be empty.",
    status: 400,
  });

  expect(parseMapSubmitBody({ format: MAP_SUBMIT_FORMAT, version: 1, maps: MAP })).toEqual({
    ok: false,
    error: "`maps` is required and must be an array.",
    status: 400,
  });
});

/**
 * The caps are the vendored catalog's, not numbers written twice. A client
 * splits its batches on `caps.submitMaps` and stops filling a body at
 * `caps.submitBytes`, and the two parting company would have the client making
 * requests it was told to make and the hub refusing them.
 */
test("the caps are the ones the vendored catalog names", () => {
  expect(MAP_SUBMIT_MAX_MAPS).toBe(catalog.caps.submitMaps);
  expect(MAP_SUBMIT_MAX_MAPS).toBe(50);
  expect(MAP_SUBMIT_MAX_BYTES).toBe(catalog.caps.submitBytes);
  expect(MAP_SUBMIT_MAX_BYTES).toBe(1048576);
});

test("a batch at the limit is accepted and one over it is refused whole", () => {
  const map = (index: number) => ({ ...MAP, map_name: `Map ${index}` });
  const atLimit = Array.from({ length: MAP_SUBMIT_MAX_MAPS }, (_, index) => map(index));

  expect(parseMapSubmitBody(body(...atLimit)).ok).toBe(true);

  expect(parseMapSubmitBody(body(...atLimit, map(MAP_SUBMIT_MAX_MAPS)))).toEqual({
    ok: false,
    error: "A batch may carry at most 50 maps. That request carried 51. Split it.",
    status: 413,
  });
});

/** One canonical name is one archive, permanently, so a batch naming a map twice
 * is a client that has misread its own map list. */
test("a repeated map name is refused rather than decided against itself", () => {
  expect(parseMapSubmitBody(body(MAP, { ...MAP, source_hash: "src-other" }))).toEqual({
    ok: false,
    error: "maps[1] names a map already in the batch.",
    status: 400,
  });
});

test("the facts a map cannot be stored without are required", () => {
  for (const field of [
    "map_name",
    "source_archive",
    "source_hash",
    "catalog_version",
    "width_elmos",
    "height_elmos",
    "world_height_min",
    "world_height_max",
  ]) {
    const without = { ...MAP, [field]: undefined };
    const parsed = entriesOf(body(without))[0];
    expect(parsed.ok).toBe(false);
  }
});

test("a field longer than the column is refused here rather than by the table", () => {
  expect(said({ map_name: "m".repeat(257) })).toBe(
    "`map_name` is required and must be a string of 1 to 256 characters.",
  );
  expect(said({ source_hash: "s".repeat(129) })).toBe(
    "`source_hash` is required and must be a string of 1 to 128 characters.",
  );
  expect(said({ description: "d".repeat(4001) })).toBe(
    "`description` must be a string of at most 4000 characters.",
  );
});

/** A count is a whole number of elmos and a version names a release of the
 * extraction code. Neither has a fractional value that means anything. */
test("a size and a version are positive integers", () => {
  expect(said({ width_elmos: 0 })).toBe("`width_elmos` is required and must be a positive integer.");
  expect(said({ width_elmos: 6144.5 })).toBe(
    "`width_elmos` is required and must be a positive integer.",
  );
  expect(said({ catalog_version: 0 })).toBe(
    "`catalog_version` is required and must be a positive integer.",
  );
});

test("a world height may be negative, and neither infinite nor missing", () => {
  expect(entry({ world_height_min: -900, world_height_max: -10 }).world_height_min).toBe(-900);
  expect(said({ world_height_min: Infinity })).toBe(
    "`world_height_min` is required and must be a number.",
  );
});

/**
 * The table's own constraints, checked here so the client is told which pair
 * disagrees. Each of them fails quietly if it gets through: a reversed range
 * reads every sample upside down and looks entirely plausible.
 */
test("the pairs that have to agree are checked before the write", () => {
  expect(said({ world_height_min: 320, world_height_max: 0 })).toBe(
    "`world_height_max` cannot be below `world_height_min`.",
  );
  expect(said({ min_wind: 40, max_wind: 5 })).toBe("`max_wind` cannot be below `min_wind`.");
  expect(said({ water_coverage: 42 })).toBe(
    "`water_coverage` is a share of the map between 0 and 1, not a percentage.",
  );
  expect(said({ void_water: true })).toBe(
    "A map with `void_water` has no water to report a `water_coverage` for.",
  );
  expect(entry({ void_water: true, water_coverage: undefined }).water_coverage).toBe(null);
});

/** mapinfo leaves these out and the engine falls back to its own defaults. A
 * zero in place of an absent value would claim a map with no wind at all. */
test("a measurement the archive does not carry stays absent", () => {
  const sparse = entry({
    min_wind: undefined,
    max_wind: undefined,
    tidal_strength: undefined,
    void_water: undefined,
    void_ground: undefined,
    water_coverage: undefined,
    appearance: undefined,
  });

  expect(sparse.min_wind).toBe(null);
  expect(sparse.tidal_strength).toBe(null);
  expect(sparse.void_water).toBe(null);
  expect(sparse.appearance).toEqual({});
});

/** mapinfo routinely carries an empty description, and the column refuses a
 * blank, so a client would have its whole entry refused over a field it did not
 * write. */
test("a blank optional field is absent rather than a refusal", () => {
  expect(entry({ display_name: "   ", description: "" }).display_name).toBe(null);
  expect(entry({ display_name: "  Comet  " }).display_name).toBe("Comet");
});

test("a map with no points at all is an ordinary map", () => {
  expect(entry({ points: undefined }).points).toEqual({ start: [], metal: [], geo: [] });
});

/** `map_point.kind` refuses anything but the three, so a client sending
 * `geothermal` would otherwise watch its geo vents disappear. */
test("a point kind outside the three is refused rather than dropped", () => {
  expect(said({ points: { geothermal: [] } })).toBe(
    "`points` names a kind the hub does not know: geothermal",
  );
});

test("a point says where it is, and may say what its kind carries", () => {
  const parsed = entry({
    points: { metal: [{ x: 1, z: 2, y: 3, meta: { amount: 2 } }] },
  });

  expect(parsed.points.metal).toEqual([{ x: 1, z: 2, y: 3, meta: { amount: 2 } }]);
  expect(said({ points: { metal: [{ x: 1 }] } })).toBe(
    "`points.metal[0]` `z` is required and must be a number.",
  );
  expect(said({ points: { metal: [{ x: 1, z: 2, colour: "red" }] } })).toBe(
    "`points.metal[0]` unknown field: colour",
  );
  expect(said({ points: { start: "512,512" } })).toBe("`points.start` must be an array.");
});

test("the reply carries the envelope a shipped build reads first", () => {
  expect(buildMapSubmitBody([{ map_name: "Comet Catcher Remake 1.8", outcome: "stored" }])).toEqual(
    {
      format: MAP_SUBMIT_FORMAT,
      version: MAP_SUBMIT_VERSION,
      results: [{ map_name: "Comet Catcher Remake 1.8", outcome: "stored" }],
    },
  );

  expect(MAP_SUBMIT_FORMAT).toBe("coilbox-hub-maps");
  expect(MAP_SUBMIT_VERSION).toBe(1);
});

/**
 * The one case the pure functions cannot answer, and it needs no Supabase:
 * `authenticateBearer` reads the header and gives up before a client is built,
 * so a request with no token is refused without the route reaching the database
 * or parsing the body.
 */
test("no token is a 401, before the body is read at all", async () => {
  const response = await POST(
    new Request("http://localhost/api/v1/maps", { method: "POST", body: "not json at all" }),
  );

  expect(response.status).toBe(401);
  expect(await response.json()).toEqual({
    error: 'Send an access token as "Authorization: Bearer <token>".',
  });
});
