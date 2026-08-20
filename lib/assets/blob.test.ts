import { afterEach, expect, mock, test } from "bun:test";

// Nothing here touches the real store. Every `put()` is an advanced operation
// out of 2,000 a month that cannot be topped up, so the SDK is faked and the
// assertions are about what this module asks it to do.
const calls: { put: unknown[][]; del: unknown[][] } = { put: [], del: [] };

// The suffix in the faked reply is what the store does with
// `addRandomSuffix: true`: a string the caller did not send and cannot work
// out. Fixed here so the assertions can name it.
const SUFFIXED = "units/bar/abc-Hn4vQ2rT8kZ1x.webp";

mock.module("@vercel/blob", () => ({
  put: (...args: unknown[]) => {
    calls.put.push(args);
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

const { BLOB_TIER_BASE, BLOB_TOKEN_ERROR, blobTierUrl, deleteBlobAssets, putBlobAsset } =
  await import("./blob");
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

  const stored = await putBlobAsset("units/bar/abc.webp", "bytes", "image/webp");

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

  const stored = await putBlobAsset("units/bar/abc.webp", "bytes", "image/webp");

  expect(stored).not.toBe("units/bar/abc.webp");
  expect(stored.startsWith("units/bar/abc")).toBe(true);
});

test("a put normalises the path the same way the URL does", async () => {
  env.BLOB_READ_WRITE_TOKEN = "vercel_blob_rw_test_token";

  await putBlobAsset("/units/bar/abc.webp", "bytes", "image/webp");

  // The object key has to match what a later delete addresses, or the store
  // keeps a copy nothing points at against a 1 GB allowance.
  expect(calls.put[0][0]).toBe("units/bar/abc.webp");
});

test("a missing token throws by name and spends no advanced operation", async () => {
  delete env.BLOB_READ_WRITE_TOKEN;

  await expect(putBlobAsset("units/bar/abc.webp", "bytes", "image/webp")).rejects.toThrow(
    BLOB_TOKEN_ERROR,
  );
  expect(calls.put).toHaveLength(0);
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
