import { afterEach, expect, test } from "bun:test";
import { StorageApiError, type SupabaseClient } from "@supabase/supabase-js";
import { bucketTierUrl, fetchApprovedStagedPicture } from "./bucket";
import { STAGED_PICTURES_BUCKET } from "./staging";

const PATH = "units/bar/buildpic/abc.webp";
const saved = process.env.VERCEL_PROJECT_PRODUCTION_URL;

afterEach(() => {
  if (saved === undefined) delete process.env.VERCEL_PROJECT_PRODUCTION_URL;
  else process.env.VERCEL_PROJECT_PRODUCTION_URL = saved;
});

test("a bucket path becomes the hub's own staged picture route on the production domain", () => {
  process.env.VERCEL_PROJECT_PRODUCTION_URL = "hub.example";

  expect(bucketTierUrl(PATH)).toBe("https://hub.example/assets/staged/units/bar/buildpic/abc.webp");
  expect(bucketTierUrl(`/${PATH}`)).toBe("https://hub.example/assets/staged/units/bar/buildpic/abc.webp");
});

test("off Vercel the route is on localhost", () => {
  delete process.env.VERCEL_PROJECT_PRODUCTION_URL;

  expect(bucketTierUrl(PATH)).toBe("http://localhost:3000/assets/staged/units/bar/buildpic/abc.webp");
});

/** The anonymous client, recording every filter the row read applies and
 *  answering with `rows`. Row level security is not simulated: the filters are
 *  what this file adds, and `supabase/tests/asset_access.test.sql` proves the
 *  policy underneath them. */
function anonWith(rows: { mime: string }[], error: unknown = null) {
  const filters: [string, unknown][] = [];
  const builder = {
    select: () => builder,
    eq: (column: string, value: unknown) => {
      filters.push([column, value]);
      return builder;
    },
    limit: () => Promise.resolve({ data: error ? null : rows, error }),
  };
  return { client: { from: () => builder } as unknown as SupabaseClient, filters };
}

/** The admin client, whose one bucket download answers `download`, recording
 *  which bucket and path were asked for. */
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

test("an approved bucket row's bytes are read from the staging bucket at its path", async () => {
  const anon = anonWith([{ mime: "image/webp" }]);
  const bytes = new Blob(["hello"]);
  const admin = adminWith({ data: bytes, error: null });

  const picture = await fetchApprovedStagedPicture(anon.client, admin.client, PATH);

  expect(picture).toEqual({ bytes, mime: "image/webp" });
  expect(admin.asked).toEqual({ bucket: STAGED_PICTURES_BUCKET, path: PATH });
});

test("the row read asks for this path, in the bucket, approved, and nothing looser", async () => {
  const anon = anonWith([{ mime: "image/webp" }]);

  await fetchApprovedStagedPicture(anon.client, adminWith({ data: new Blob([]), error: null }).client, PATH);

  expect(anon.filters).toEqual([
    ["path", PATH],
    ["tier", "bucket"],
    ["moderation", "approved"],
  ]);
});

// Pending, rejected and missing are one case here: the filters above and the
// read policy both turn the first two into no row, the same as the third.
test("no approved row is null, and the bucket is never asked", async () => {
  const admin = adminWith({ data: new Blob(["unreviewed"]), error: null });

  expect(await fetchApprovedStagedPicture(anonWith([]).client, admin.client, PATH)).toBeNull();
  expect(admin.asked).toEqual({});
});

test("an approved row with no object behind it is null, the same as no row", async () => {
  const gone = new StorageApiError("Object not found", 400, "404", "storage", "NoSuchKey");

  expect(
    await fetchApprovedStagedPicture(
      anonWith([{ mime: "image/webp" }]).client,
      adminWith({ data: null, error: gone }).client,
      PATH,
    ),
  ).toBeNull();
});

test("any other bucket failure throws rather than answering null", async () => {
  const down = new StorageApiError("Internal error", 500, "500", "storage", "InternalError");

  await expect(
    fetchApprovedStagedPicture(
      anonWith([{ mime: "image/webp" }]).client,
      adminWith({ data: null, error: down }).client,
      PATH,
    ),
  ).rejects.toBe(down);
});

test("a failed row read throws rather than reading as no row", async () => {
  const failure = { message: "connection refused" };

  await expect(
    fetchApprovedStagedPicture(anonWith([], failure).client, adminWith({ data: null, error: null }).client, PATH),
  ).rejects.toBe(failure);
});
