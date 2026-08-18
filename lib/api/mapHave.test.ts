import { expect, test } from "bun:test";
import { POST } from "@/app/api/v1/maps/have/route";
import type { MapCatalogState } from "@/lib/maps/have";
import catalog from "@/lib/maps/vendor/map-catalog.json";
import {
  buildMapHaveBody,
  MAP_HAVE_FORMAT,
  MAP_HAVE_MAX_KEYS,
  MAP_HAVE_VERSION,
  type MapHaveKey,
  parseMapHaveBody,
} from "./mapHave";

const KEY = {
  map_name: "Comet Catcher Remake 1.8",
  source_hash: "src-a",
  catalog_version: 3,
};

function keysOf(body: unknown): MapHaveKey[] {
  const parsed = parseMapHaveBody(body);
  if (!parsed.ok) throw new Error(parsed.error);
  return parsed.keys;
}

/** What the hub holds, keyed the way `fetchMapCatalogState` keys it. */
function held(...rows: [string, string, number][]): Map<string, MapCatalogState> {
  return new Map(
    rows.map(([mapName, sourceHash, catalogVersion]) => [
      mapName,
      { sourceHash, catalogVersion },
    ]),
  );
}

function statusesFor(body: unknown, stored: Map<string, MapCatalogState>): string[] {
  return buildMapHaveBody(keysOf(body), stored).results.map((result) => result.status);
}

test("a key parses into the three facts that decide a status", () => {
  expect(keysOf({ keys: [KEY] })[0]).toEqual({
    mapName: "Comet Catcher Remake 1.8",
    sourceHash: "src-a",
    catalogVersion: 3,
  });
});

test("a body that is not an object is rejected", () => {
  for (const body of [[], null, "keys", 3]) {
    expect(parseMapHaveBody(body).ok).toBe(false);
  }
});

test("an unknown field on the body is rejected rather than ignored", () => {
  const parsed = parseMapHaveBody({ keys: [KEY], since: "yesterday" });

  expect(parsed).toEqual({ ok: false, error: "Unknown field: since", status: 400 });
});

test("an unknown field on a key is rejected, naming which key", () => {
  const parsed = parseMapHaveBody({ keys: [KEY, { ...KEY, facts_digest: "d" }] });

  expect(parsed).toEqual({
    ok: false,
    error: "keys[1] unknown field: facts_digest",
    status: 400,
  });
});

test("a key that is not an object is rejected", () => {
  expect(parseMapHaveBody({ keys: ["Comet Catcher Remake 1.8"] })).toEqual({
    ok: false,
    error: "keys[0] must be a JSON object.",
    status: 400,
  });
});

test("a missing or empty map name is rejected with the length the table accepts", () => {
  expect(parseMapHaveBody({ keys: [{ ...KEY, map_name: "  " }] })).toEqual({
    ok: false,
    error: "keys[0] `map_name` is required and must be a string of 1 to 256 characters.",
    status: 400,
  });
});

test("a map name longer than the column accepts is refused before it reaches the database", () => {
  expect(parseMapHaveBody({ keys: [{ ...KEY, map_name: "m".repeat(257) }] })).toEqual({
    ok: false,
    error: "keys[0] `map_name` is required and must be a string of 1 to 256 characters.",
    status: 400,
  });

  expect(keysOf({ keys: [{ ...KEY, map_name: "m".repeat(256) }] })).toHaveLength(1);
});

test("a source hash longer than the column accepts is refused", () => {
  expect(parseMapHaveBody({ keys: [{ ...KEY, source_hash: "s".repeat(129) }] })).toEqual({
    ok: false,
    error: "keys[0] `source_hash` is required and must be a string of 1 to 128 characters.",
    status: 400,
  });
});

/**
 * Every status turns on comparing this number, so a key without one cannot be
 * answered at all. Read as "as old as possible" it reports the whole corpus as
 * `have` and loses every improvement, and read as "as new as possible" it asks
 * for every map back.
 */
test("a key with no catalog version is refused rather than defaulted", () => {
  const withoutVersion = { map_name: KEY.map_name, source_hash: KEY.source_hash };

  expect(parseMapHaveBody({ keys: [withoutVersion] })).toEqual({
    ok: false,
    error: "keys[0] `catalog_version` is required and must be an integer of 1 or more.",
    status: 400,
  });
});

test("a catalog version that is not an integer is refused rather than rounded", () => {
  for (const version of [3.5, "3", null, true, Number.NaN, Infinity]) {
    expect(parseMapHaveBody({ keys: [{ ...KEY, catalog_version: version }] }).ok).toBe(false);
  }
});

/** The column's check is `> 0`, so a key the table could never hold is refused
 * here rather than looked up and answered. */
test("a catalog version of zero or below is refused, matching the column's check", () => {
  for (const version of [0, -1]) {
    expect(parseMapHaveBody({ keys: [{ ...KEY, catalog_version: version }] })).toEqual({
      ok: false,
      error: "keys[0] `catalog_version` is required and must be an integer of 1 or more.",
      status: 400,
    });
  }

  expect(keysOf({ keys: [{ ...KEY, catalog_version: 1 }] })[0].catalogVersion).toBe(1);
});

test("an empty batch is rejected, since it asks nothing", () => {
  expect(parseMapHaveBody({ keys: [] })).toEqual({
    ok: false,
    error: "`keys` must not be empty.",
    status: 400,
  });
});

test("keys has to be an array", () => {
  expect(parseMapHaveBody({ keys: { map_name: "x" } })).toEqual({
    ok: false,
    error: "`keys` is required and must be an array.",
    status: 400,
  });
});

/**
 * The cap is the vendored catalog's, not a number written twice. A client splits
 * its batches on `caps.haveKeys` and this is what refuses one over it, so the two
 * parting company would have the client making requests it was told to make and
 * the hub refusing them.
 */
test("the cap is the one the vendored catalog names", () => {
  expect(MAP_HAVE_MAX_KEYS).toBe(catalog.caps.haveKeys);
  expect(MAP_HAVE_MAX_KEYS).toBe(500);
});

test("a batch at the limit is accepted and one over it is refused whole", () => {
  const key = (index: number) => ({ ...KEY, map_name: `Map ${index}` });
  const atLimit = Array.from({ length: MAP_HAVE_MAX_KEYS }, (_, index) => key(index));

  expect(parseMapHaveBody({ keys: atLimit }).ok).toBe(true);

  expect(parseMapHaveBody({ keys: [...atLimit, key(MAP_HAVE_MAX_KEYS)] })).toEqual({
    ok: false,
    error: "A batch may carry at most 500 keys. That request carried 501. Split it.",
    status: 413,
  });
});

test("a repeated map name is rejected rather than answered twice", () => {
  const parsed = parseMapHaveBody({ keys: [KEY, { ...KEY, source_hash: "src-z" }] });

  expect(parsed).toEqual({
    ok: false,
    error: "keys[1] repeats a key already in the batch.",
    status: 400,
  });
});

test("a body carries the format marker, the version and one result per key in request order", () => {
  const body = buildMapHaveBody(
    keysOf({
      keys: [
        { ...KEY, map_name: "Zed 1.0" },
        { ...KEY, map_name: "Alpha 1.0" },
        { ...KEY, map_name: "Middle 1.0" },
      ],
    }),
    held(["Alpha 1.0", "src-a", 3]),
  );

  expect(body.format).toBe(MAP_HAVE_FORMAT);
  expect(body.format).toBe("coilbox-hub-map-have");
  expect(body.version).toBe(MAP_HAVE_VERSION);
  expect(body.version).toBe(1);
  expect(body.results).toEqual([
    { map_name: "Zed 1.0", status: "missing" },
    { map_name: "Alpha 1.0", status: "have" },
    { map_name: "Middle 1.0", status: "missing" },
  ]);
});

test("a map the hub has never seen is missing", () => {
  expect(statusesFor({ keys: [KEY] }, new Map())).toEqual(["missing"]);
});

test("a matching hash at a matching version is have", () => {
  expect(statusesFor({ keys: [KEY] }, held(["Comet Catcher Remake 1.8", "src-a", 3]))).toEqual([
    "have",
  ]);
});

/**
 * The whole reason `catalog_version` is on the key. The same archive read by a
 * newer coilbox is a better entry, and a client that heard `have` would never
 * send the improvement.
 */
test("a matching hash at a higher client version is changed, so the better entry arrives", () => {
  expect(statusesFor({ keys: [KEY] }, held(["Comet Catcher Remake 1.8", "src-a", 2]))).toEqual([
    "changed",
  ]);
});

/**
 * The other half of the same rule. The stored row came from a better read of the
 * same bytes, so an old build hears `have` and cannot talk the catalog backwards
 * by being enthusiastic.
 */
test("a matching hash at a lower client version is have", () => {
  expect(statusesFor({ keys: [KEY] }, held(["Comet Catcher Remake 1.8", "src-a", 9]))).toEqual([
    "have",
  ]);
});

/**
 * A different hash is a different archive, and no comparison of extraction
 * versions applies across two archives. A newer read of an older archive is not
 * an improvement on the map the hub holds.
 */
test("a different hash is changed whatever the versions say", () => {
  for (const storedVersion of [1, 3, 9]) {
    expect(
      statusesFor({ keys: [KEY] }, held(["Comet Catcher Remake 1.8", "src-other", storedVersion])),
    ).toEqual(["changed"]);
  }
});

/**
 * `public.map` has no moderation state to keep out of an answer, which is the
 * restraint the asset check exercises. The equivalent here is that the answer says
 * what to do and never what the hub holds: the stored `catalog_version` is a number
 * a client could aim at instead of reporting the version its own extraction ran.
 */
test("a result says what to do and nothing about what the hub holds", () => {
  const body = buildMapHaveBody(
    keysOf({ keys: [KEY] }),
    held(["Comet Catcher Remake 1.8", "src-stored", 7]),
  );

  expect(Object.keys(body.results[0]).sort()).toEqual(["map_name", "status"]);
  expect(JSON.stringify(body)).not.toContain("src-stored");
  expect(JSON.stringify(body)).not.toContain("catalog_version");
});

/**
 * The one case the pure functions cannot answer, and it needs no Supabase:
 * `authenticateBearer` reads the header and gives up before it builds a client, so
 * a request with no token is refused without the route reaching the database or
 * even parsing the body.
 */
test("no token is a 401, before the body is read at all", async () => {
  const response = await POST(
    new Request("http://localhost/api/v1/maps/have", {
      method: "POST",
      body: "not json at all",
    }),
  );

  expect(response.status).toBe(401);
  expect(await response.json()).toEqual({
    error: 'Send an access token as "Authorization: Bearer <token>".',
  });
});
