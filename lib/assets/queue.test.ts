import { expect, test } from "bun:test";
import { StorageApiError, type SupabaseClient } from "@supabase/supabase-js";
import { BLOB_TIER_BASE } from "./blob";
import { fetchAssetObject, pictureCaption, pictureIds, QUEUE_PAGE_SIZE } from "./queue";
import { STAGED_PICTURES_BUCKET } from "./staging";

const ID = "0f8fad5b-d9cb-469f-a165-70867728950e";

test("a unit caption names the game, since unit names repeat across games", () => {
  expect(
    pictureCaption({
      game: "bar",
      unit_name: "armsolar",
      map_name: null,
      variant: "buildpic",
    }),
  ).toEqual({ name: "armsolar", detail: "bar buildpic" });
});

test("a map caption names no game, since a map is not scoped to one", () => {
  expect(
    pictureCaption({
      game: null,
      unit_name: null,
      map_name: "Tangerine 1.1",
      variant: "minimap",
    }),
  ).toEqual({ name: "Tangerine 1.1", detail: "minimap" });
});

test("an id that is not a uuid never reaches a filter", () => {
  expect(pictureIds([ID, "", "*", "id.neq.null", "'; update asset set moderation"])).toEqual([ID]);
});

/** A repeated id is a form bug rather than a second row, and a filter that names
 * the same row twice would report two rows approved where one moved. */
test("a repeated id collapses to one", () => {
  expect(pictureIds([ID, ID, ID])).toEqual([ID]);
});

/** The grid never posts more than a page, so anything larger is a hand written
 * request, and the cap is what stops one approving the whole table at once. */
test("a submission cannot act on more rows than a page holds", () => {
  const many = Array.from(
    { length: QUEUE_PAGE_SIZE + 50 },
    (_, index) => `0f8fad5b-d9cb-469f-a165-${String(index).padStart(12, "0")}`,
  );

  expect(pictureIds(many)).toHaveLength(QUEUE_PAGE_SIZE);
});

/** A client whose one `asset` row is whatever the test hands it, and whose
 * `staged-pictures` download answers whatever the test hands it too. Only the
 * bucket tests set a download result. The Blob tests never reach `./staging`
 * at all. */
function oneRow(
  row: { path: string; tier: string; mime: string } | null,
  download: { data: unknown; error: unknown } = { data: null, error: null },
): SupabaseClient {
  const builder = {
    select: () => builder,
    eq: () => builder,
    maybeSingle: () => Promise.resolve({ data: row, error: null }),
  };
  return {
    from: () => builder,
    storage: { from: () => ({ download: () => Promise.resolve(download) }) },
  } as unknown as SupabaseClient;
}

test("the moderation thumbnail fetches a Blob row from its Blob URL", async () => {
  expect(
    await fetchAssetObject(oneRow({ path: "units/bar/buildpic/abc-Xy9.webp", tier: "blob", mime: "image/webp" }), ID),
  ).toEqual({
    source: "url",
    url: `${BLOB_TIER_BASE}units/bar/buildpic/abc-Xy9.webp`,
    mime: "image/webp",
  });
});

test("the moderation thumbnail reads a bucket row's bytes from the staging bucket", async () => {
  const bytes = new Blob(["hello"]);
  const object = await fetchAssetObject(
    oneRow(
      { path: "units/bar/buildpic/abc.webp", tier: "bucket", mime: "image/webp" },
      { data: bytes, error: null },
    ),
    ID,
  );

  expect(object).toEqual({ source: "bytes", bytes, mime: "image/webp" });
});

test("a bucket row with no object at its path is treated as a missing row", async () => {
  const object = await fetchAssetObject(
    oneRow(
      { path: "units/bar/buildpic/gone.webp", tier: "bucket", mime: "image/webp" },
      { data: null, error: new StorageApiError("Object not found", 400, "404", "storage", "NoSuchKey") },
    ),
    ID,
  );

  expect(object).toBeNull();
});

test("a bucket row that fails to download for another reason throws rather than answering null", async () => {
  const failure = fetchAssetObject(
    oneRow(
      { path: "units/bar/buildpic/abc.webp", tier: "bucket", mime: "image/webp" },
      { data: null, error: new StorageApiError("Service unavailable", 500, "503", "storage", "InternalError") },
    ),
    ID,
  );

  await expect(failure).rejects.toThrow("Service unavailable");
});

test("the staging download reaches the staged-pictures bucket", async () => {
  let seenBucket: string | undefined;
  const builder = {
    select: () => builder,
    eq: () => builder,
    maybeSingle: () =>
      Promise.resolve({
        data: { path: "units/bar/buildpic/abc.webp", tier: "bucket", mime: "image/webp" },
        error: null,
      }),
  };
  const supabase = {
    from: () => builder,
    storage: {
      from: (bucket: string) => {
        seenBucket = bucket;
        return { download: () => Promise.resolve({ data: new Blob(["hello"]), error: null }) };
      },
    },
  } as unknown as SupabaseClient;

  await fetchAssetObject(supabase, ID);

  expect(seenBucket).toBe(STAGED_PICTURES_BUCKET);
});
