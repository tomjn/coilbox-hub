import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import type { AssetIdentity } from "./asset";
import type { AssetLicenceRow, AssetRedistribution } from "./licence";
import {
  ACCOUNT_STORAGE_QUOTA_BYTES,
  ASSET_MAX_OBJECT_BYTES,
  type AssetUploadDeclaration,
  MONTHLY_UPLOAD_BUDGET,
  SUBJECT_UPLOADS_PER_HOUR,
  UNIT_VARIANT_CEILING,
  checkAssetUpload,
} from "./upload";

const USER = "11111111-1111-1111-1111-111111111111";

const UNIT: AssetIdentity = {
  keyedOn: "unit",
  game: "BYAR",
  unitName: "armsolar",
  variant: "buildpic",
};

const MAP: AssetIdentity = {
  keyedOn: "map",
  mapName: "Comet Catcher Remake 1.8",
  variant: "minimap",
};

function declaration(overrides: Partial<AssetUploadDeclaration> = {}): AssetUploadDeclaration {
  return {
    identity: UNIT,
    sourceHash: "raw-abc",
    hash: "encabc",
    encodeProfile: "buildpic-q80",
    origin: "extracted",
    mime: "image/webp",
    bytes: 4096,
    mapWidth: null,
    mapHeight: null,
    worldHeightMin: null,
    worldHeightMax: null,
    sourceArchive: "byar_1.2.sdz",
    ...overrides,
  };
}

function licence(
  extracted: AssetRedistribution,
  rendered: AssetRedistribution = extracted,
): AssetLicenceRow {
  return {
    id: "licence",
    game: "BYAR",
    map_name: null,
    all_maps: null,
    licence: "GPL-2.0-or-later",
    licence_url: "https://example.test",
    notes: null,
    decision: null,
    decided_at: null,
    checked_at: "2026-08-14T00:00:00Z",
    checked_by: "test",
    redistribute_extracted: extracted,
    redistribute_rendered: rendered,
    created_at: "2026-08-14T00:00:00Z",
    updated_at: "2026-08-14T00:00:00Z",
  };
}

interface ExistingRow {
  id: string;
  source_hash: string;
  uploaded_by: string | null;
  moderation: string;
  bytes: number;
}

/** The row this identity already has, owned by the caller and holding source
 * bytes a newer archive has moved on from. */
function held(overrides: Partial<ExistingRow> = {}): ExistingRow {
  return {
    id: "held",
    source_hash: "raw-old",
    uploaded_by: USER,
    moderation: "approved",
    bytes: 4096,
    ...overrides,
  };
}

interface World {
  gameLicence: AssetLicenceRow | null;
  perMapLicence: AssetLicenceRow | null;
  allMapsLicence: AssetLicenceRow | null;
  existing: ExistingRow | null;
  unitVariants: number;
  accountBytes: number;
  recent: number;
  thisMonth: number;
  broken?: "licence" | "identity" | "bytes";
}

function world(overrides: Partial<World> = {}): World {
  return {
    gameLicence: licence("allowed"),
    perMapLicence: null,
    allMapsLicence: licence("allowed"),
    existing: null,
    unitVariants: 0,
    accountBytes: 0,
    recent: 0,
    thisMonth: 0,
    ...overrides,
  };
}

interface Query {
  table: string;
  filters: string[];
}

/**
 * A stand in for the secret key client, recording which filters each query
 * carried so the answers can be told apart. Nothing here touches Postgres or
 * Blob: what is under test is the order of the refusals and which one wins,
 * and a live stack would make that a slower version of the same assertion.
 */
function fakeSupabase(state: World, seen: Query[] = []): SupabaseClient {
  const answer = (query: Query): unknown => {
    seen.push(query);

    if (query.table === "asset_licence") {
      if (state.broken === "licence") return { data: null, error: { message: "down" } };
      if (query.filters.includes("eq:all_maps")) {
        return { data: state.allMapsLicence, error: null };
      }
      if (query.filters.includes("eq:map_name")) {
        return { data: state.perMapLicence, error: null };
      }
      return { data: state.gameLicence, error: null };
    }

    if (query.filters.some((filter) => filter.startsWith("or:"))) {
      if (state.broken === "identity") return { data: null, error: { message: "down" } };
      return { data: state.existing, error: null };
    }
    if (query.filters.includes("not:uploaded_by")) {
      return { count: state.thisMonth, error: null };
    }
    if (query.filters.includes("eq:uploaded_by")) {
      return { count: state.recent, error: null };
    }
    return { count: state.unitVariants, error: null };
  };

  const from = (table: string) => {
    const query: Query = { table, filters: [] };
    const builder = {
      select: () => builder,
      eq: (column: string) => {
        query.filters.push(`eq:${column}`);
        return builder;
      },
      gte: (column: string) => {
        query.filters.push(`gte:${column}`);
        return builder;
      },
      not: (column: string) => {
        query.filters.push(`not:${column}`);
        return builder;
      },
      or: (filter: string) => {
        query.filters.push(`or:${filter}`);
        return builder;
      },
      maybeSingle: () => Promise.resolve(answer(query)),
      then: (onOk: (value: unknown) => unknown, onErr?: (reason: unknown) => unknown) =>
        Promise.resolve(answer(query)).then(onOk, onErr),
    };
    return builder;
  };

  const rpc = () =>
    Promise.resolve(
      state.broken === "bytes"
        ? { data: null, error: { message: "down" } }
        : { data: state.accountBytes, error: null },
    );

  return { from, rpc } as unknown as SupabaseClient;
}

async function check(state: World, overrides: Partial<AssetUploadDeclaration> = {}) {
  return checkAssetUpload(fakeSupabase(state), USER, declaration(overrides));
}

test("a declaration that clears everything comes back with the path the bytes go to", async () => {
  expect(await check(world())).toEqual({
    ok: true,
    path: "units/BYAR/buildpic/encabc.webp",
    replacing: null,
  });
});

/**
 * The pure checks come first so that the request that was never going to be
 * accepted costs no round trip at all, and the write costs nothing either way.
 */
test("a type outside the allowlist is refused without asking the database", async () => {
  const seen: Query[] = [];
  const result = await checkAssetUpload(
    fakeSupabase(world(), seen),
    USER,
    declaration({ mime: "image/gif" }),
  );

  expect(result).toEqual({
    ok: false,
    error: "`mime` must be one of image/webp, image/png.",
    status: 415,
  });
  expect(seen).toEqual([]);
});

test("an object over the cap is refused without asking the database", async () => {
  const seen: Query[] = [];
  const result = await checkAssetUpload(
    fakeSupabase(world(), seen),
    USER,
    declaration({ bytes: ASSET_MAX_OBJECT_BYTES + 1 }),
  );

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.status).toBe(413);
  expect(seen).toEqual([]);
});

test("an identity that cannot be spelled as a path is refused without asking the database", async () => {
  const seen: Query[] = [];
  const result = await checkAssetUpload(
    fakeSupabase(world(), seen),
    USER,
    declaration({ identity: { ...UNIT, game: "../etc" } }),
  );

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.status).toBe(400);
  expect(seen).toEqual([]);
});

/**
 * The staging tier is public, so `put()` publishes. Accepting bytes the hub has
 * no recorded permission to redistribute would spend an advanced operation
 * putting them somewhere reachable, and leave deletion as the only remedy.
 */
test("a game with no licence row at all publishes nothing", async () => {
  const result = await check(world({ gameLicence: null }));

  expect(result).toEqual({
    ok: false,
    error: 'The hub has no recorded permission to redistribute extracted pictures for "BYAR".',
    status: 403,
  });
});

test("undecided and refused both block, since only one of them is worth looking at again", async () => {
  expect((await check(world({ gameLicence: licence("unknown") }))).ok).toBe(false);
  expect((await check(world({ gameLicence: licence("denied") }))).ok).toBe(false);
});

test("permission to extract is not permission to render", async () => {
  const permissive = world({ gameLicence: licence("allowed", "denied") });

  expect((await check(permissive, { origin: "extracted" })).ok).toBe(true);
  expect((await check(permissive, { origin: "rendered" })).ok).toBe(false);
});

/**
 * Nobody can tell from the bytes what a supplied image is a picture of, so no
 * per game decision can answer for one. `mayRedistribute()` does not take the
 * origin at all, and the moderation queue is what stands in front of the class.
 */
test("an image somebody supplied is not licence gated, it is queued", async () => {
  expect((await check(world({ gameLicence: null }), { origin: "uploaded" })).ok).toBe(true);
});

test("a map with no row of its own falls back to the blanket row", async () => {
  const mapUpload = { identity: MAP, mapWidth: 8192, mapHeight: 8192 };

  expect(
    (await check(world({ perMapLicence: null, allMapsLicence: licence("allowed") }), mapUpload)).ok,
  ).toBe(true);
  expect(
    (await check(world({ perMapLicence: null, allMapsLicence: null }), mapUpload)).ok,
  ).toBe(false);
});

test("a map's own row wins over the blanket one, including a refusal", async () => {
  const result = await check(
    world({ perMapLicence: licence("denied"), allMapsLicence: licence("allowed") }),
    { identity: MAP, mapWidth: 8192, mapHeight: 8192 },
  );

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.status).toBe(403);
});

/**
 * The replacement half of #106. A newer archive changes the bytes for an
 * identity the hub already holds, and the row it holds is updated rather than a
 * second one added.
 */
test("a newer archive replaces the row it already has rather than being refused", async () => {
  expect(await check(world({ existing: held() }))).toEqual({
    ok: true,
    path: "units/BYAR/buildpic/encabc.webp",
    replacing: "held",
  });
});

/**
 * A replacement resets the row to pending, so anybody able to replace anybody's
 * asset could take every approved picture off the site one request at a time.
 */
test("only the account that uploaded an asset may replace it", async () => {
  const stranger = held({ uploaded_by: "22222222-2222-2222-2222-222222222222" });
  const result = await check(world({ existing: stranger }));

  expect(result).toEqual({
    ok: false,
    error: "Another account uploaded the asset with that identity, so it cannot be replaced.",
    status: 409,
  });
});

test("a seeded row belongs to nobody and so is nobody's to replace", async () => {
  expect((await check(world({ existing: held({ uploaded_by: null }) }))).ok).toBe(false);
});

/** A safety rejection is not overridable (#115), and a replacement that put the
 * row back to pending would make it overridable by anybody with other bytes. */
test("a rejection is not something an upload can undo", async () => {
  const result = await check(world({ existing: held({ moderation: "rejected" }) }));

  expect(result).toEqual({
    ok: false,
    error: "That asset was rejected, and a rejection is not something an upload can undo.",
    status: 409,
  });
});

/** The have check answers this for free and in batches, and re-storing the same
 * source bytes would spend an advanced operation to reset an approved row. */
test("the same source bytes again are refused rather than stored twice", async () => {
  const result = await check(world({ existing: held({ source_hash: "raw-abc" }) }));

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.status).toBe(409);
  expect(result.error).toContain("/api/v1/assets/have");
});

/** Every check a first upload faces, a replacement faces. The licence is the
 * one that matters most: permission withdrawn is withdrawn for both. */
test("a replacement is refused when the subject's permission has been revoked", async () => {
  expect(
    (await check(world({ existing: held(), gameLicence: licence("denied") }))).ok,
  ).toBe(false);
});

test("a unit at the variant ceiling takes no more", async () => {
  expect((await check(world({ unitVariants: UNIT_VARIANT_CEILING - 1 }))).ok).toBe(true);

  const result = await check(world({ unitVariants: UNIT_VARIANT_CEILING }));
  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.status).toBe(409);
});

/** The ceiling is on stored variants and a replacement stores no new one, so it
 * is measured against what the table holds afterwards rather than before. */
test("a unit at the variant ceiling can still have one of its variants replaced", async () => {
  const atCeiling = world({ existing: held(), unitVariants: UNIT_VARIANT_CEILING });

  expect((await check(atCeiling)).ok).toBe(true);
});

test("the variant ceiling is a unit rule and does not apply to maps", async () => {
  const result = await check(world({ unitVariants: UNIT_VARIANT_CEILING + 50 }), {
    identity: MAP,
    mapWidth: 8192,
    mapHeight: 8192,
  });

  expect(result.ok).toBe(true);
});

test("the account quota counts what this upload would add, not what is already there", async () => {
  const almostFull = ACCOUNT_STORAGE_QUOTA_BYTES - 4096;

  expect((await check(world({ accountBytes: almostFull }))).ok).toBe(true);

  const result = await check(world({ accountBytes: almostFull + 1 }));
  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.status).toBe(413);
});

/** The quota is measured over rows, and a replacement leaves one row where
 * there was one, so the superseded row's bytes come back off the total. */
test("a replacement is charged the difference rather than the whole object", async () => {
  const full = { existing: held({ bytes: 4096 }), accountBytes: ACCOUNT_STORAGE_QUOTA_BYTES };

  expect((await check(world(full), { bytes: 4096 })).ok).toBe(true);
  expect((await check(world(full), { bytes: 4097 })).ok).toBe(false);
});

test("a client looping on one subject is slowed down rather than served", async () => {
  const result = await check(world({ recent: SUBJECT_UPLOADS_PER_HOUR }));

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.status).toBe(429);
});

test("the month's allowance stops the whole hub, not one account", async () => {
  expect((await check(world({ thisMonth: MONTHLY_UPLOAD_BUDGET - 1 }))).ok).toBe(true);

  const result = await check(world({ thisMonth: MONTHLY_UPLOAD_BUDGET }));
  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.status).toBe(503);
});

test("the budget leaves a margin under the store's advertised allowance", () => {
  expect(MONTHLY_UPLOAD_BUDGET).toBeLessThan(2000);
});

/**
 * A failed quota query read as zero would let every limit through at exactly
 * the moment the database is unwell, which is when a runaway client is least
 * likely to be noticed.
 */
test("a quota that cannot be read refuses the upload rather than allowing it", async () => {
  for (const broken of ["licence", "identity", "bytes"] as const) {
    const result = await check(world({ broken }));
    expect(result.ok).toBe(false);
    if (result.ok) return;
    expect(result.status).toBe(503);
  }
});

test("a request that trips two limits always hears about the same one", async () => {
  const result = await check(
    world({ existing: held({ moderation: "rejected" }), recent: SUBJECT_UPLOADS_PER_HOUR }),
  );

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.error).toContain("rejected");
});
