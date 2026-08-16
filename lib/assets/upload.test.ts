import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import type { AssetIdentity } from "./asset";
import { ASSET_CAPS, heightOverlayMaxBytes } from "./caps";
import {
  ACCOUNT_STORAGE_QUOTA_BYTES,
  ASSET_MAX_OBJECT_BYTES,
  type AssetUploadDeclaration,
  MONTHLY_UPLOAD_BUDGET,
  SUBJECT_UPLOADS_PER_HOUR,
  UNIT_RENDER_CEILING,
  checkAssetUpload,
} from "./upload";

const USER = "11111111-1111-1111-1111-111111111111";

/** What the route worked out from the bytes (#154), which is a parameter here
 * and never a field on the declaration. Short and hex rather than a real
 * SHA-256, so the expected paths below stay readable. */
const HASH = "encabc";

const UNIT: AssetIdentity = {
  keyedOn: "unit",
  game: "BYAR",
  unitName: "armsolar",
  variant: "buildpic",
};

const RENDER: AssetIdentity = { ...UNIT, variant: "render:315" };

const MAP: AssetIdentity = {
  keyedOn: "map",
  mapName: "Comet Catcher Remake 1.8",
  variant: "minimap",
};

/** The one class with no cap of its own, so the backstop is what refuses it. */
const HEIGHT_OVERLAY: AssetIdentity = { ...MAP, variant: "overlay:height" };

function declaration(overrides: Partial<AssetUploadDeclaration> = {}): AssetUploadDeclaration {
  return {
    identity: UNIT,
    sourceHash: "raw-abc",
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

interface ExistingRow {
  id: string;
  source_hash: string;
  source_archive: string;
  uploaded_by: string | null;
  moderation: string;
  bytes: number;
}

/** The row this identity already has, owned by the caller and holding source
 * bytes a newer archive has moved on from. The archive is the older one, so the
 * default is an ordinary version rollover rather than the anomaly #116 is
 * about. */
function held(overrides: Partial<ExistingRow> = {}): ExistingRow {
  return {
    id: "held",
    source_hash: "raw-old",
    source_archive: "byar_1.1.sdz",
    uploaded_by: USER,
    moderation: "approved",
    bytes: 4096,
    ...overrides,
  };
}

interface World {
  existing: ExistingRow | null;
  /** What `reusable_staging_object` answers: an object already holding these
   * bytes, or null for the ordinary case where the store has never seen them. */
  stored: string | null;
  unitRenders: number;
  accountBytes: number;
  recent: number;
  thisMonth: number;
  broken?: "identity" | "bytes" | "reuse";
}

function world(overrides: Partial<World> = {}): World {
  return {
    existing: null,
    stored: null,
    unitRenders: 0,
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

    // Nothing on this path may read the licence table (#167), so the fake will
    // not answer for it. A gate put back here fails loudly rather than passing
    // because the stand in happened to hand it a permissive row.
    if (query.table === "asset_licence") {
      throw new Error("the upload path must not consult asset_licence");
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
    return { count: state.unitRenders, error: null };
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
      like: (column: string, pattern: string) => {
        query.filters.push(`like:${column}:${pattern}`);
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

  const rpc = (name: string, args: Record<string, unknown>) => {
    seen.push({ table: `rpc:${name}`, filters: Object.values(args).map(String) });

    if (name === "reusable_staging_object") {
      return Promise.resolve(
        state.broken === "reuse"
          ? { data: null, error: { message: "down" } }
          : { data: state.stored, error: null },
      );
    }

    return Promise.resolve(
      state.broken === "bytes"
        ? { data: null, error: { message: "down" } }
        : { data: state.accountBytes, error: null },
    );
  };

  return { from, rpc } as unknown as SupabaseClient;
}

async function check(state: World, overrides: Partial<AssetUploadDeclaration> = {}) {
  return checkAssetUpload(fakeSupabase(state), USER, declaration(overrides), HASH);
}

test("a declaration that clears everything comes back with the path the bytes go to", async () => {
  expect(await check(world())).toEqual({
    ok: true,
    path: "units/BYAR/buildpic/encabc.webp",
    replacing: null,
  });
});

/**
 * The whole of #154 in one assertion. A map path carries no map name, so the
 * leaf is the entire filename, and the leaf follows the hash argument, which
 * the route takes from the bytes. Nothing on the declaration reaches it, so
 * there is no value an uploader can send that moves the picture somewhere else.
 */
test("the path leaf is the hash the caller computed and nothing off the declaration", async () => {
  const minimap = { identity: MAP, mapWidth: 8192, mapHeight: 8192 };

  const mine = await checkAssetUpload(fakeSupabase(world()), USER, declaration(minimap), "mine");
  const yours = await checkAssetUpload(fakeSupabase(world()), USER, declaration(minimap), "yours");

  expect(mine).toMatchObject({ ok: true, path: "maps/minimap/mine.webp" });
  expect(yours).toMatchObject({ ok: true, path: "maps/minimap/yours.webp" });
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
    HASH,
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
    HASH,
  );

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.status).toBe(413);
  expect(seen).toEqual([]);
});

/**
 * A class with a fixed longest edge says what its bytes may be far more tightly
 * than the backstop does, and the dimension cap does not say it: metadata is
 * unbounded and no decoder reads it, so a 256px buildpic can weigh two
 * megabytes and still measure 256px.
 */
test("the object cap is the class's rather than one number for everything", async () => {
  const buildpicCap = ASSET_CAPS.buildpic.maxBytes as number;
  expect(buildpicCap).toBeLessThan(ASSET_MAX_OBJECT_BYTES);

  expect((await check(world(), { bytes: buildpicCap })).ok).toBe(true);

  const result = await check(world(), { bytes: buildpicCap + 1 });
  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.status).toBe(413);
  expect(result.error).toContain("buildpic");
});

/** The metal and type overlays are sampled from grids nobody has measured, so
 * there is nothing to derive a number from and the backstop answers for them. */
test("a class with no cap of its own takes the backstop", async () => {
  const overlay = {
    identity: { ...MAP, variant: "overlay:metal" },
    mime: "image/webp",
    mapWidth: 8192,
    mapHeight: 8192,
  };

  expect(ASSET_CAPS["overlay:metal"].maxBytes).toBeNull();
  expect((await check(world(), { ...overlay, bytes: ASSET_MAX_OBJECT_BYTES })).ok).toBe(true);
  expect((await check(world(), { ...overlay, bytes: ASSET_MAX_OBJECT_BYTES + 1 })).ok).toBe(false);
});

/**
 * #142. The height overlay is 16 bit at the map's own resolution, so the
 * backstop was refusing seven of the ninety seven maps in the collection with a
 * 413 naming a number chosen for classes nobody expected to reach it. The cap it
 * takes now moves with the map, which is the same derivation the classes with a
 * fixed edge get.
 */
test("a height overlay is capped by the map it is a picture of", async () => {
  const overlay = { identity: HEIGHT_OVERLAY, mime: "image/png" };
  // 32x32, the largest size BAR publishes. Its largest measured overlay is
  // 4,511,410 bytes, which the backstop refused.
  const largest = { ...overlay, mapWidth: 16384, mapHeight: 16384 };
  const cap = heightOverlayMaxBytes("overlay:height", 16384, 16384) as number;

  expect((await check(world(), { ...largest, bytes: 4_511_410 })).ok).toBe(true);
  expect((await check(world(), { ...largest, bytes: cap })).ok).toBe(true);

  const over = await check(world(), { ...largest, bytes: cap + 1 });
  expect(over.ok).toBe(false);
  if (over.ok) return;
  expect(over.status).toBe(413);
  expect(over.error).toContain("overlay:height");
});

/** The other direction, and the reason a number per map beats one number. A
 * small map's overlay has no business weighing what a large one's does, and the
 * old backstop let it. */
test("a small map's height overlay is held to a much tighter number", async () => {
  const small = {
    identity: HEIGHT_OVERLAY,
    mime: "image/png",
    // 8x8, the smallest in the collection, whose measured overlay is 2,117
    // bytes.
    mapWidth: 4096,
    mapHeight: 4096,
  };

  expect(heightOverlayMaxBytes("overlay:height", 4096, 4096)).toBe(513 * 513 * 2);
  expect((await check(world(), { ...small, bytes: 513 * 513 * 2 })).ok).toBe(true);
  expect((await check(world(), { ...small, bytes: ASSET_MAX_OBJECT_BYTES })).ok).toBe(false);
});

test("an identity that cannot be spelled as a path is refused without asking the database", async () => {
  const seen: Query[] = [];
  const result = await checkAssetUpload(
    fakeSupabase(world(), seen),
    USER,
    declaration({ identity: { ...UNIT, game: "../etc" } }),
    HASH,
  );

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.status).toBe(400);
  expect(seen).toEqual([]);
});

/**
 * #167, and the whole of the policy in one assertion. `asset_licence` used to
 * decide this, and since a subject with no row reads as `unknown`, it refused
 * every game nobody had researched. The upload that found it was Splinter
 * Faction, which has one of the clearest and most permissive licences in the
 * corpus and was refused with a 403.
 *
 * The table is not consulted rather than consulted and ignored. A lookup whose
 * answer is thrown away leaves the refusal one line from coming back, and puts
 * a round trip and a 503 on the path for a question nothing asks.
 */
test("a game with no recorded licence is accepted, and the table is never asked", async () => {
  const seen: Query[] = [];
  const result = await checkAssetUpload(fakeSupabase(world(), seen), USER, declaration(), HASH);

  expect(result).toEqual({
    ok: true,
    path: "units/BYAR/buildpic/encabc.webp",
    replacing: null,
  });
  expect(seen.some((query) => query.table === "asset_licence")).toBe(false);
});

/** Extraction was gated separately from rendering and an uploaded image was
 * gated by nothing, on the reasoning that no per game decision can answer for
 * bytes somebody supplied. That reasoning holds for all three: the queue is
 * what looks at the picture. */
test("no origin is licence gated, every one of them goes to the queue", async () => {
  for (const origin of ["extracted", "rendered", "uploaded"] as const) {
    expect((await check(world(), { origin })).ok).toBe(true);
  }
});

/** Maps had two lookups rather than one, a per map row and the blanket row that
 * covered every map without one. Both are gone from this path with the rest. */
test("a map upload asks no licence question either", async () => {
  const seen: Query[] = [];
  const result = await checkAssetUpload(
    fakeSupabase(world(), seen),
    USER,
    declaration({ identity: MAP, mapWidth: 8192, mapHeight: 8192 }),
    HASH,
  );

  expect(result.ok).toBe(true);
  expect(seen.some((query) => query.table === "asset_licence")).toBe(false);
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

/**
 * #132. Two units in a game shipping the same placeholder buildpic encode to the
 * same bytes, and the hub computes the hash from the bytes (#154), so the second
 * upload can be recognised before it spends one advanced operation out of 2,000
 * a month writing the store what it already holds.
 */
test("bytes the store already holds come back as an object to reuse", async () => {
  const already = "units/BYAR/buildpic/encabc-Hn4vQ2rT.webp";

  expect(await check(world({ stored: already }))).toEqual({
    ok: true,
    path: "units/BYAR/buildpic/encabc.webp",
    replacing: null,
    stored: already,
  });
});

test("the lookup is on the hash the hub computed and nothing off the declaration", async () => {
  const seen: Query[] = [];
  await checkAssetUpload(fakeSupabase(world(), seen), USER, declaration(), HASH);

  expect(seen.find((query) => query.table === "rpc:reusable_staging_object")?.filters).toEqual([
    HASH,
  ]);
});

/** An optimisation that fails should cost an operation, not an upload. Every
 * other query in this file is a limit, and a limit that cannot be read refuses.
 * This one is not a limit. */
test("a reuse lookup that fails writes the object rather than refusing the upload", async () => {
  expect(await check(world({ broken: "reuse", stored: "units/BYAR/buildpic/encabc-x.webp" }))).toEqual({
    ok: true,
    path: "units/BYAR/buildpic/encabc.webp",
    replacing: null,
  });
});

/**
 * The cheap signal #116 asks for, and the reason it rides along on the answer
 * rather than being one.
 *
 * An identity replaced from a different archive is a version rollover, which is
 * what every replacement test above is, and none of them comes back with a
 * conflict on it. Same archive, different raw bytes is the odd one.
 */
test("an ordinary version rollover carries no conflict", async () => {
  const result = await check(world({ existing: held() }));

  expect(result).toEqual({
    ok: true,
    path: "units/BYAR/buildpic/encabc.webp",
    replacing: "held",
  });
});

test("the same archive reporting different source bytes is noted, and still accepted", async () => {
  const result = await check(world({ existing: held({ source_archive: "byar_1.2.sdz" }) }));

  expect(result).toEqual({
    ok: true,
    path: "units/BYAR/buildpic/encabc.webp",
    replacing: "held",
    conflict: {
      assetId: "held",
      sourceArchive: "byar_1.2.sdz",
      heldSourceHash: "raw-old",
      reportedSourceHash: "raw-abc",
      reportedBy: USER,
    },
  });
});

/**
 * The case the issue is actually about, and the one only-the-uploader-may-
 * replace already stopped dead and stopped silently. The refusal is unchanged
 * and the note is what the queue gets out of it.
 */
test("a second account reporting different bytes for the same archive is refused and noted", async () => {
  const stranger = held({
    source_archive: "byar_1.2.sdz",
    uploaded_by: "22222222-2222-2222-2222-222222222222",
  });
  const result = await check(world({ existing: stranger }));

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.status).toBe(409);
  expect(result.conflict?.reportedBy).toBe(USER);
  expect(result.conflict?.assetId).toBe("held");
});

/** Comparing on the encoded hash would fire on every Coilbox and libwebp
 * upgrade at once. The rule reads `source_hash`, and the encoded hash the hub
 * computed never enters it. */
test("a changed encoded hash on the same source bytes is not a conflict, it is a duplicate", async () => {
  const existing = held({ source_archive: "byar_1.2.sdz", source_hash: "raw-abc" });
  const result = await checkAssetUpload(
    fakeSupabase(world({ existing })),
    USER,
    declaration(),
    "encdifferent",
  );

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.conflict).toBeUndefined();
  expect(result.error).toContain("/api/v1/assets/have");
});

/** Every check a first upload faces, a replacement faces, and the licence is
 * now not one of them on either. A newer archive for a subject nobody has
 * researched refreshes the picture (#167). */
test("a replacement is not licence gated either", async () => {
  const seen: Query[] = [];
  const result = await checkAssetUpload(
    fakeSupabase(world({ existing: held() }), seen),
    USER,
    declaration(),
    HASH,
  );

  expect(result).toMatchObject({ ok: true, replacing: "held" });
  expect(seen.some((query) => query.table === "asset_licence")).toBe(false);
});

test("a unit at the render ceiling takes no more", async () => {
  const render = { identity: RENDER, origin: "rendered" as const };

  expect((await check(world({ unitRenders: UNIT_RENDER_CEILING - 1 }), render)).ok).toBe(true);

  const result = await check(world({ unitRenders: UNIT_RENDER_CEILING }), render);
  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.status).toBe(409);
});

/** The ceiling is on stored renders and a replacement stores no new one, so it
 * is measured against what the table holds afterwards rather than before. */
test("a unit at the render ceiling can still have one of its renders replaced", async () => {
  const atCeiling = world({ existing: held(), unitRenders: UNIT_RENDER_CEILING });

  expect((await check(atCeiling, { identity: RENDER, origin: "rendered" })).ok).toBe(true);
});

/**
 * The correction #107 asks for. Counting every variant made a unit full of
 * renders turn away its buildpic, which is the one picture every unit wants and
 * the class the issue calls negligible. It counts renders, so it refuses
 * renders. The buildpic never asks, so the count is not even read.
 */
test("a unit full of renders still takes its buildpic", async () => {
  const seen: Query[] = [];
  const result = await checkAssetUpload(
    fakeSupabase(world({ unitRenders: UNIT_RENDER_CEILING + 50 }), seen),
    USER,
    declaration(),
    HASH,
  );

  expect(result.ok).toBe(true);
  expect(seen.some((query) => query.filters.some((filter) => filter.startsWith("like:")))).toBe(
    false,
  );
});

test("the render ceiling is a unit rule and does not apply to maps", async () => {
  const result = await check(world({ unitRenders: UNIT_RENDER_CEILING + 50 }), {
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

/**
 * #107 asks for this one per user per game, and on a unit asset that is what the
 * subject is. Asserted on the filters rather than on the count, because a limit
 * that counted the account's uploads across every game would pass every count
 * based test in this file and still be the wrong rule.
 */
test("the hourly limit is scoped to one account and one game", async () => {
  const seen: Query[] = [];
  await checkAssetUpload(fakeSupabase(world(), seen), USER, declaration(), HASH);

  const hourly = seen.find((query) => query.filters.includes("eq:uploaded_by"));
  expect(hourly?.filters).toEqual(["eq:uploaded_by", "eq:game", "gte:seen_at"]);
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
  for (const broken of ["identity", "bytes"] as const) {
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
