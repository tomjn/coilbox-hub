import { afterEach, expect, mock, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";

// Nothing here touches the real store. Every `put()` is an advanced operation
// out of 2,000 a month that cannot be topped up, so the SDK is faked and the
// assertions are about what this module asks it to do.
const calls: { put: unknown[][]; del: unknown[][] } = { put: [], del: [] };

/** What the next put does instead of succeeding, when a test says so. */
let putFails: Error | null = null;

class BlobStoreSuspendedError extends Error {}

// The suffix in the faked reply is what the store does with
// `addRandomSuffix: true`: a string the caller did not send and cannot work
// out. Fixed here so the assertions can name it.
const SUFFIXED = "units/bar/abc-Hn4vQ2rT8kZ1x.webp";

mock.module("@vercel/blob", () => ({
  BlobStoreSuspendedError,
  put: (...args: unknown[]) => {
    calls.put.push(args);
    if (putFails) return Promise.reject(putFails);
    return Promise.resolve({
      pathname: SUFFIXED,
      url: `https://eyugwjvmp953ayog.public.blob.vercel-storage.com/${SUFFIXED}`,
    });
  },
  del: (...args: unknown[]) => {
    calls.del.push(args);
    return Promise.resolve();
  },
}));

const {
  BLOB_BUDGET_ERROR,
  BLOB_LEDGER_ERROR,
  BLOB_SUSPENDED_ERROR,
  BLOB_TIER_BASE,
  BLOB_TOKEN_ERROR,
  blobTierUrl,
  deleteBlobAssets,
  putBlobAsset,
  putBlobGameImage,
} = await import("./blob");
const { BLOB_PUT_BUDGET } = await import("./blobLedger");

/**
 * The two functions the reservation calls, over a list of reservations. `full`
 * is the reserve function answering null, and `down` is the database failing.
 */
function ledger(state: "room" | "full" | "down" = "room") {
  const reservations: { id: number; kind: string; budget: number }[] = [];
  const released: number[] = [];

  const client = {
    rpc: (name: string, args: Record<string, unknown>) => {
      if (state === "down") return Promise.resolve({ data: null, error: { message: "down" } });
      if (name === "reserve_blob_put") {
        if (state === "full") return Promise.resolve({ data: null, error: null });
        const id = reservations.length + 1;
        reservations.push({ id, kind: args.put_kind as string, budget: args.budget as number });
        return Promise.resolve({ data: id, error: null });
      }
      released.push(args.put_id as number);
      return Promise.resolve({ data: true, error: null });
    },
  } as unknown as SupabaseClient;

  return { client, reservations, released };
}
const blobModule = await import("./blob");

// Typed read-only by Next.js. Tests are the one place that legitimately varies it.
const env = process.env as Record<string, string | undefined>;
const original = env.BLOB_READ_WRITE_TOKEN;

afterEach(() => {
  // See lib/assets/cdn.test.ts: assigning undefined stores the word rather than
  // removing the variable.
  if (original === undefined) delete env.BLOB_READ_WRITE_TOKEN;
  else env.BLOB_READ_WRITE_TOKEN = original;
  calls.put = [];
  calls.del = [];
  putFails = null;
});

test("the module does not offer list, head or copy at all", () => {
  // Rule one and rule two, as structure rather than as advice: an author who
  // never reads issue #99 cannot reach the metered lookups through this module,
  // and the ESLint rule stops the direct import that would get round it.
  const exported = Object.keys(blobModule);

  expect(exported).not.toContain("list");
  expect(exported).not.toContain("head");
  expect(exported).not.toContain("copy");
});

test("the base ends in exactly one slash, so joining is a concatenation", () => {
  expect(BLOB_TIER_BASE.endsWith("/")).toBe(true);
  expect(BLOB_TIER_BASE.endsWith("//")).toBe(false);
});

test("a tier relative path resolves whichever side carries the slash", () => {
  expect(blobTierUrl("units/bar/abc.webp")).toBe(
    "https://eyugwjvmp953ayog.public.blob.vercel-storage.com/units/bar/abc.webp",
  );
  expect(blobTierUrl("/units/bar/abc.webp")).toBe(
    "https://eyugwjvmp953ayog.public.blob.vercel-storage.com/units/bar/abc.webp",
  );
});

test("nested tier relative paths survive intact", () => {
  expect(blobTierUrl("units/bar/render/270/0a1b2c3d.webp")).toBe(
    "https://eyugwjvmp953ayog.public.blob.vercel-storage.com/units/bar/render/270/0a1b2c3d.webp",
  );
});

test("a put is public and suffixed, and answers with where the bytes went", async () => {
  env.BLOB_READ_WRITE_TOKEN = "vercel_blob_rw_test_token";

  const stored = await putBlobAsset(ledger().client, "units/bar/abc.webp", "bytes", "image/webp");

  expect(calls.put).toHaveLength(1);
  expect(calls.put[0][0]).toBe("units/bar/abc.webp");
  expect(calls.put[0][1]).toBe("bytes");
  expect(calls.put[0][2]).toEqual({
    access: "public",
    addRandomSuffix: true,
    contentType: "image/webp",
    token: "vercel_blob_rw_test_token",
  });
  expect(stored).toBe(SUFFIXED);
});

test("the path that comes back is the store's and not the one asked for", async () => {
  // The defect in #131, as a test. A row holding the requested path holds a
  // path the uploader derived from bytes it has, so the pending object it
  // points at is one anybody who can produce those bytes can reach.
  env.BLOB_READ_WRITE_TOKEN = "vercel_blob_rw_test_token";

  const stored = await putBlobAsset(ledger().client, "units/bar/abc.webp", "bytes", "image/webp");

  expect(stored).not.toBe("units/bar/abc.webp");
  expect(stored.startsWith("units/bar/abc")).toBe(true);
});

test("a put normalises the path the same way the URL does", async () => {
  env.BLOB_READ_WRITE_TOKEN = "vercel_blob_rw_test_token";

  await putBlobAsset(ledger().client, "/units/bar/abc.webp", "bytes", "image/webp");

  // The object key has to match what a later delete addresses, or the store
  // keeps a copy nothing points at against a 1 GB allowance.
  expect(calls.put[0][0]).toBe("units/bar/abc.webp");
});

test("a missing token throws by name and spends no advanced operation", async () => {
  delete env.BLOB_READ_WRITE_TOKEN;

  await expect(putBlobAsset(ledger().client, "units/bar/abc.webp", "bytes", "image/webp")).rejects.toThrow(
    BLOB_TOKEN_ERROR,
  );
  expect(calls.put).toHaveLength(0);
});

test("every put reserves its operation first, against the 30 day budget", async () => {
  env.BLOB_READ_WRITE_TOKEN = "vercel_blob_rw_test_token";
  const assets = ledger();
  const games = ledger();

  await putBlobAsset(assets.client, "units/bar/abc.webp", "bytes", "image/webp");
  await putBlobGameImage(games.client, "games/BA/logo.webp", "bytes", "image/webp");

  expect(assets.reservations).toEqual([{ id: 1, kind: "asset", budget: BLOB_PUT_BUDGET }]);
  expect(games.reservations).toEqual([{ id: 1, kind: "game_image", budget: BLOB_PUT_BUDGET }]);
  expect(calls.put).toHaveLength(2);
});

test("a used up budget refuses before the store is asked for anything", async () => {
  env.BLOB_READ_WRITE_TOKEN = "vercel_blob_rw_test_token";

  await expect(
    putBlobAsset(ledger("full").client, "units/bar/abc.webp", "bytes", "image/webp"),
  ).rejects.toThrow(BLOB_BUDGET_ERROR);
  await expect(
    putBlobGameImage(ledger("full").client, "games/BA/logo.webp", "bytes", "image/webp"),
  ).rejects.toThrow(BLOB_BUDGET_ERROR);
  expect(calls.put).toHaveLength(0);
});

test("a reservation that cannot be written is a refusal, not an uncounted put", async () => {
  env.BLOB_READ_WRITE_TOKEN = "vercel_blob_rw_test_token";

  await expect(
    putBlobAsset(ledger("down").client, "units/bar/abc.webp", "bytes", "image/webp"),
  ).rejects.toThrow(BLOB_LEDGER_ERROR);
  expect(calls.put).toHaveLength(0);
});

test("a put that fails keeps its reservation, because it may have landed", async () => {
  env.BLOB_READ_WRITE_TOKEN = "vercel_blob_rw_test_token";
  putFails = new Error("socket hang up");
  const counted = ledger();

  await expect(
    putBlobAsset(counted.client, "units/bar/abc.webp", "bytes", "image/webp"),
  ).rejects.toThrow("socket hang up");
  expect(counted.released).toEqual([]);
});

test("a suspended store gives the reservation back and says so", async () => {
  env.BLOB_READ_WRITE_TOKEN = "vercel_blob_rw_test_token";
  putFails = new BlobStoreSuspendedError("Vercel Blob: This store has been suspended.");
  const assets = ledger();
  const games = ledger();

  await expect(
    putBlobAsset(assets.client, "units/bar/abc.webp", "bytes", "image/webp"),
  ).rejects.toThrow(BLOB_SUSPENDED_ERROR);
  await expect(
    putBlobGameImage(games.client, "games/BA/logo.webp", "bytes", "image/webp"),
  ).rejects.toThrow(BLOB_SUSPENDED_ERROR);
  expect(assets.released).toEqual([1]);
  expect(games.released).toEqual([1]);
});

test("a delete passes the whole batch through as object keys", async () => {
  env.BLOB_READ_WRITE_TOKEN = "vercel_blob_rw_test_token";

  await deleteBlobAssets(["units/bar/abc.webp", "/maps/def.webp"]);

  expect(calls.del).toHaveLength(1);
  expect(calls.del[0][0]).toEqual(["units/bar/abc.webp", "maps/def.webp"]);
  expect(calls.del[0][1]).toEqual({ token: "vercel_blob_rw_test_token" });
});

test("an empty batch makes no request at all", async () => {
  env.BLOB_READ_WRITE_TOKEN = "vercel_blob_rw_test_token";

  await deleteBlobAssets([]);

  expect(calls.del).toHaveLength(0);
});
