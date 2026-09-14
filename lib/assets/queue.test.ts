import { expect, test } from "bun:test";
import type { SupabaseClient } from "@supabase/supabase-js";
import { BLOB_TIER_BASE } from "./blob";
import { fetchAssetObject, pictureCaption, pictureIds, QUEUE_PAGE_SIZE } from "./queue";

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

/** A client whose one `asset` row is whatever the test hands it. */
function oneRow(row: { path: string; tier: string; mime: string } | null): SupabaseClient {
  const builder = {
    select: () => builder,
    eq: () => builder,
    maybeSingle: () => Promise.resolve({ data: row, error: null }),
  };
  return { from: () => builder } as unknown as SupabaseClient;
}

test("the moderation thumbnail fetches a Blob row from its Blob URL", async () => {
  expect(
    await fetchAssetObject(oneRow({ path: "units/bar/buildpic/abc-Xy9.webp", tier: "blob", mime: "image/webp" }), ID),
  ).toEqual({ url: `${BLOB_TIER_BASE}units/bar/buildpic/abc-Xy9.webp`, mime: "image/webp" });
});

/** Until #333 reads the bucket, the route answers 404 for these rather than
 * fetching a Blob URL built from a path that was never in Blob. */
test("a row in the bucket has no object URL for the thumbnail route to fetch", async () => {
  expect(
    await fetchAssetObject(oneRow({ path: "units/bar/buildpic/abc.webp", tier: "bucket", mime: "image/webp" }), ID),
  ).toBeNull();
});
