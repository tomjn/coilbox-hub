import { expect, test } from "bun:test";
import { StorageApiError, type SupabaseClient } from "@supabase/supabase-js";
import {
  downloadStagedAsset,
  putStagedAsset,
  putStagedGameImage,
  removeStagedAssets,
  STAGED_PICTURES_BUCKET,
  StagedAssetExistsError,
} from "./staging";

// Nothing here touches the real bucket. `supabase.storage` is faked at the
// method level, one layer below the SDK, so the assertions are about what
// this module asks the Storage client to do and how it reacts to what comes
// back, including the exact shapes the real client answered with when probed
// against the local stack (see the module's own comments).

type StorageResult = { data: unknown; error: unknown };

function fakeStorage() {
  const calls: { upload: unknown[][]; download: unknown[][]; remove: unknown[][] } = {
    upload: [],
    download: [],
    remove: [],
  };
  const buckets: string[] = [];

  let uploadResult: StorageResult = { data: { path: "" }, error: null };
  let downloadResult: StorageResult = { data: new Blob(["bytes"]), error: null };
  let removeResult: StorageResult = { data: [], error: null };

  const supabase = {
    storage: {
      from: (bucket: string) => {
        buckets.push(bucket);
        return {
          upload: (...args: unknown[]) => {
            calls.upload.push(args);
            return Promise.resolve(uploadResult);
          },
          download: (...args: unknown[]) => {
            calls.download.push(args);
            return Promise.resolve(downloadResult);
          },
          remove: (...args: unknown[]) => {
            calls.remove.push(args);
            return Promise.resolve(removeResult);
          },
        };
      },
    },
  } as unknown as SupabaseClient;

  return {
    supabase,
    calls,
    buckets,
    setUploadResult: (result: StorageResult) => {
      uploadResult = result;
    },
    setDownloadResult: (result: StorageResult) => {
      downloadResult = result;
    },
    setRemoveResult: (result: StorageResult) => {
      removeResult = result;
    },
  };
}

test("the module does not offer list or any other listing call", async () => {
  // Postgres already knows every object, because every object has a row
  // (issue #331).
  const staging = await import("./staging");
  const exported = Object.keys(staging);

  expect(exported).not.toContain("list");
  expect(exported).not.toContain("listStagedAssets");
});

test("a put reaches the staged-pictures bucket with upsert false", async () => {
  const store = fakeStorage();

  await putStagedAsset(store.supabase, "units/bar/abc.webp", "bytes", "image/webp");

  expect(store.buckets).toEqual([STAGED_PICTURES_BUCKET]);
  expect(store.calls.upload).toHaveLength(1);
  expect(store.calls.upload[0][0]).toBe("units/bar/abc.webp");
  expect(store.calls.upload[0][1]).toBe("bytes");
  expect(store.calls.upload[0][2]).toEqual({ contentType: "image/webp", upsert: false });
});

test("a put normalises a leading slash", async () => {
  const store = fakeStorage();

  await putStagedAsset(store.supabase, "/units/bar/abc.webp", "bytes", "image/webp");

  expect(store.calls.upload[0][0]).toBe("units/bar/abc.webp");
});

test("a collision on upsert false is StagedAssetExistsError, not the raw error", async () => {
  // The exact shape the local stack answers a colliding upload with:
  // StorageApiError, statusCode "409", code "KeyAlreadyExists".
  const store = fakeStorage();
  store.setUploadResult({
    data: null,
    error: new StorageApiError("The resource already exists", 400, "409", "storage", "KeyAlreadyExists"),
  });

  await expect(putStagedAsset(store.supabase, "units/bar/abc.webp", "bytes", "image/webp")).rejects.toThrow(
    StagedAssetExistsError,
  );
});

test("any other upload failure is thrown as-is, not folded into StagedAssetExistsError", async () => {
  const store = fakeStorage();
  store.setUploadResult({
    data: null,
    error: new StorageApiError("mime type text/plain is not supported", 400, "415", "storage", "InvalidMimeType"),
  });

  const failure = putStagedAsset(store.supabase, "units/bar/abc.webp", "bytes", "text/plain");

  await expect(failure).rejects.not.toBeInstanceOf(StagedAssetExistsError);
  await expect(failure).rejects.toThrow("mime type text/plain is not supported");
});

test("a game image upload overwrites in place", async () => {
  const store = fakeStorage();

  await putStagedGameImage(store.supabase, "games/BA/logo.webp", "bytes", "image/webp");

  expect(store.calls.upload[0][0]).toBe("games/BA/logo.webp");
  expect(store.calls.upload[0][2]).toEqual({ contentType: "image/webp", upsert: true });
});

test("a game image upload that fails throws the raw error, never StagedAssetExistsError", async () => {
  // upsert: true never collides, but a failure for any other reason should
  // not be reinterpreted as an existence conflict.
  const store = fakeStorage();
  store.setUploadResult({
    data: null,
    error: new StorageApiError("The resource already exists", 400, "409", "storage", "KeyAlreadyExists"),
  });

  const failure = putStagedGameImage(store.supabase, "games/BA/logo.webp", "bytes", "image/webp");

  await expect(failure).rejects.not.toBeInstanceOf(StagedAssetExistsError);
});

test("a download reaches the bucket and returns the bytes", async () => {
  const store = fakeStorage();
  const bytes = new Blob(["hello"]);
  store.setDownloadResult({ data: bytes, error: null });

  const result = await downloadStagedAsset(store.supabase, "units/bar/abc.webp");

  expect(store.buckets).toEqual([STAGED_PICTURES_BUCKET]);
  expect(store.calls.download[0][0]).toBe("units/bar/abc.webp");
  expect(result).toBe(bytes);
});

test("a download of a missing object throws the raw NoSuchKey error", async () => {
  const store = fakeStorage();
  store.setDownloadResult({
    data: null,
    error: new StorageApiError("Object not found", 400, "404", "storage", "NoSuchKey"),
  });

  await expect(downloadStagedAsset(store.supabase, "units/bar/gone.webp")).rejects.toThrow("Object not found");
});

test("a remove passes the whole batch through as normalised keys", async () => {
  const store = fakeStorage();

  await removeStagedAssets(store.supabase, ["units/bar/abc.webp", "/maps/def.webp"]);

  expect(store.buckets).toEqual([STAGED_PICTURES_BUCKET]);
  expect(store.calls.remove[0][0]).toEqual(["units/bar/abc.webp", "maps/def.webp"]);
});

test("removing a path that is already gone is not an error", async () => {
  // Confirmed against the local stack: remove() answers `{ data: [], error:
  // null }` for a path with nothing at it, alone or alongside a path that did
  // exist. This module does no extra work for that case because the Storage
  // API already behaves this way, so the test pins the fake to what was
  // observed rather than a guess.
  const store = fakeStorage();
  store.setRemoveResult({ data: [], error: null });

  await expect(removeStagedAssets(store.supabase, ["units/bar/already-gone.webp"])).resolves.toBeUndefined();
});

test("an empty batch makes no request at all", async () => {
  const store = fakeStorage();

  await removeStagedAssets(store.supabase, []);

  expect(store.calls.remove).toHaveLength(0);
  expect(store.buckets).toEqual([]);
});

test("a remove failure for a reason other than a missing path still throws", async () => {
  const store = fakeStorage();
  store.setRemoveResult({
    data: null,
    error: new StorageApiError("The resource was not found", 400, "404", "storage", "BucketNotFound"),
  });

  await expect(removeStagedAssets(store.supabase, ["units/bar/abc.webp"])).rejects.toThrow(
    "The resource was not found",
  );
});
