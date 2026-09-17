import { expect, test } from "bun:test";
import { StorageApiError, type SupabaseClient } from "@supabase/supabase-js";
import { staticTierUrl } from "@/lib/assets/cdn";
import { encodedHash } from "@/lib/assets/hash";
import { STAGED_PICTURES_BUCKET } from "@/lib/assets/staging";
import { fetchGameArt, gameArtUrl } from "./art";

const PATH = "games/BA/logo.webp";
const BYTES = "logo bytes";
const HASH = await encodedHash(new TextEncoder().encode(BYTES).buffer as ArrayBuffer);

test("art with no staged copy is drawn from the durable tier", () => {
  expect(gameArtUrl("BA", "logo", { path: PATH, hash: HASH, staged_tier: null })).toBe(staticTierUrl(PATH));
  // Imported straight to the durable tier, so no hash was ever recorded.
  expect(gameArtUrl("BA", "logo", { path: PATH, hash: null, staged_tier: null })).toBe(staticTierUrl(PATH));
});

test("art staged in the bucket is drawn from the hub's route, named by its hash", () => {
  expect(gameArtUrl("BA", "banner", { path: "games/BA/banner.png", hash: HASH, staged_tier: "bucket" })).toBe(
    `/assets/games/BA/banner/${HASH}`,
  );
});

test("an unrecognised staged tier, a bucket row with no hash, and no art at all draw nothing", () => {
  expect(gameArtUrl("BA", "logo", { path: PATH, hash: HASH, staged_tier: "unknown" })).toBeNull();
  expect(gameArtUrl("BA", "logo", { path: PATH, hash: null, staged_tier: "bucket" })).toBeNull();
  expect(gameArtUrl("BA", "logo", { path: null, hash: null, staged_tier: null })).toBeNull();
});

/** The anonymous client, recording the columns and filters of the row read and
 *  answering with `row`. Row level security is not simulated, so a hidden game
 *  is the policy answering no row, which is `row = null` here.
 *  `game_visibility.test.sql` proves the policy. */
function anonWith(row: Record<string, string | null> | null, error: unknown = null) {
  const asked: { table?: string; columns?: string; filters: [string, unknown][] } = { filters: [] };
  const builder = {
    select: (columns: string) => {
      asked.columns = columns;
      return builder;
    },
    eq: (column: string, value: unknown) => {
      asked.filters.push([column, value]);
      return builder;
    },
    maybeSingle: () => Promise.resolve({ data: error ? null : row, error }),
  };
  const client = {
    from: (table: string) => {
      asked.table = table;
      return builder;
    },
  } as unknown as SupabaseClient;
  return { client, asked };
}

function adminWith(download: { data: unknown; error: unknown }) {
  const asked: { bucket?: string; path?: string } = {};
  const client = {
    storage: {
      from: (bucket: string) => {
        asked.bucket = bucket;
        return {
          download: (path: string) => {
            asked.path = path;
            return Promise.resolve(download);
          },
        };
      },
    },
  } as unknown as SupabaseClient;
  return { client, asked };
}

const stagedRow = { logo_path: PATH, logo_staged_tier: "bucket" };

test("a logo staged in the bucket under the row's hash is served from the bucket, typed by its path", async () => {
  const anon = anonWith(stagedRow);
  const admin = adminWith({ data: new Blob([BYTES]), error: null });

  const art = await fetchGameArt(anon.client, admin.client, "BA", "logo", HASH);

  expect(art && "bytes" in art ? { text: await art.bytes.text(), mime: art.mime } : art).toEqual({
    text: BYTES,
    mime: "image/webp",
  });
  expect(admin.asked).toEqual({ bucket: STAGED_PICTURES_BUCKET, path: PATH });
  expect(anon.asked).toEqual({
    table: "game",
    columns: "logo_path,logo_staged_tier",
    filters: [
      ["shortname", "BA"],
      ["logo_hash", HASH],
    ],
  });
});

test("a banner reads the banner columns and a PNG path is typed as PNG", async () => {
  const anon = anonWith({ banner_path: "games/BA/banner.png", banner_staged_tier: "bucket" });
  const art = await fetchGameArt(
    anon.client,
    adminWith({ data: new Blob([BYTES]), error: null }).client,
    "BA",
    "banner",
    HASH,
  );

  expect(art && "mime" in art ? art.mime : null).toBe("image/png");
  expect(anon.asked.columns).toBe("banner_path,banner_staged_tier");
  expect(anon.asked.filters).toContainEqual(["banner_hash", HASH]);
});

// A wrong hash, a hidden game and an unknown shortname are one case here: the
// hash filter and the read policy all turn them into no row.
test("no row for this game and hash is null, and the bucket is never asked", async () => {
  const admin = adminWith({ data: new Blob([BYTES]), error: null });

  expect(await fetchGameArt(anonWith(null).client, admin.client, "BA", "logo", "b".repeat(64))).toBeNull();
  expect(admin.asked).toEqual({});
});

test("an unrecognised staged tier is null, and the bucket is never asked", async () => {
  const admin = adminWith({ data: new Blob([BYTES]), error: null });

  expect(
    await fetchGameArt(anonWith({ logo_path: PATH, logo_staged_tier: "unknown" }).client, admin.client, "BA", "logo", HASH),
  ).toBeNull();
  expect(admin.asked).toEqual({});
});

// An upload writes the object before the row. In between, the old hash must not
// be answered with the new bytes, because that answer is cached for a year.
test("bucket bytes that do not hash to the URL's hash are null", async () => {
  const art = await fetchGameArt(
    anonWith(stagedRow).client,
    adminWith({ data: new Blob(["the next upload"]), error: null }).client,
    "BA",
    "logo",
    HASH,
  );

  expect(art).toBeNull();
});

test("a promoted logo whose row still names the hash answers with the durable tier URL", async () => {
  const admin = adminWith({ data: new Blob([BYTES]), error: null });

  const art = await fetchGameArt(
    anonWith({ logo_path: PATH, logo_staged_tier: null }).client,
    admin.client,
    "BA",
    "logo",
    HASH,
  );

  expect(art).toEqual({ promoted: staticTierUrl(PATH) });
  expect(admin.asked).toEqual({});
});

test("a row naming the hash with no path is null", async () => {
  expect(
    await fetchGameArt(
      anonWith({ logo_path: null, logo_staged_tier: null }).client,
      adminWith({ data: null, error: null }).client,
      "BA",
      "logo",
      HASH,
    ),
  ).toBeNull();
});

test("a staged row with no object behind it is null, and any other bucket failure throws", async () => {
  const gone = new StorageApiError("Object not found", 400, "404", "storage", "NoSuchKey");
  expect(
    await fetchGameArt(anonWith(stagedRow).client, adminWith({ data: null, error: gone }).client, "BA", "logo", HASH),
  ).toBeNull();

  const down = new StorageApiError("Internal error", 500, "500", "storage", "InternalError");
  await expect(
    fetchGameArt(anonWith(stagedRow).client, adminWith({ data: null, error: down }).client, "BA", "logo", HASH),
  ).rejects.toBe(down);
});

test("a failed row read throws rather than reading as no row", async () => {
  const failure = { message: "connection refused" };

  await expect(
    fetchGameArt(anonWith(null, failure).client, adminWith({ data: null, error: null }).client, "BA", "logo", HASH),
  ).rejects.toBe(failure);
});
