/**
 * The one place that talks to `supabase.storage`, for the staging tier that is
 * replacing Vercel Blob (issue #331). Nothing else may touch it: the ESLint
 * rule that bans `.storage` outside this file, alongside the one banning
 * `@vercel/blob` outside `./blob`, is in `eslint.config.mjs`.
 *
 * The bucket is `staged-pictures`, created by #330: private, so `anon` and
 * `authenticated` cannot list, read or write any path in it, and only a
 * client holding the secret key (`lib/supabase/admin.ts`) can. Because it is
 * private there is no suffix to hide a pending upload behind, unlike
 * `./blob`'s `addRandomSuffix` (#131): every path here is the content
 * addressed one `assetObjectPath` in `./path` already builds, and the same
 * bytes always land at the same key.
 *
 * Three calls, matching what `./blob` offers and no more:
 *
 * 1. `putStagedAsset` uploads with `upsert: false`, because the row for a
 *    freshly hashed upload should never already have an object behind it,
 *    and a collision is worth telling apart from any other failure
 *    ({@link StagedAssetExistsError}).
 * 2. `putStagedGameImage` uploads with `upsert: true`, the one write that
 *    overwrites, mirroring `putBlobGameImage` in `./blob`: a game's logo or
 *    banner has a deterministic path and a replacement is meant to replace.
 * 3. `downloadStagedAsset` reads the bytes back, for moderation and
 *    promotion.
 * 4. `removeStagedAssets` deletes a batch. Verified against the local stack:
 *    the Storage API does not error on a path that is already gone, single or
 *    batched, so promotion and the sweep can delete twice without either
 *    call failing.
 *
 * Nothing here lists the bucket. Postgres already knows every object, because
 * every object has a `public.asset` or `public.game` row, the same reasoning
 * `./blob` documents for `list()` and `head()`.
 */

import { type SupabaseClient, StorageApiError } from "@supabase/supabase-js";

/** The bucket `#330` created. Private, `image/webp` and `image/png` only, one
 *  object per hash. */
export const STAGED_PICTURES_BUCKET = "staged-pictures";

/**
 * What an upload body may be. The same shape `./blob` accepts, for the same
 * reason: a route handler has an `ArrayBuffer`, a `File` (which is a `Blob`)
 * or `request.body`, and a Node stream is not offered because it only works
 * off the edge.
 */
export type StagedAssetBody = ArrayBuffer | Blob | ReadableStream | string;

/**
 * `putStagedAsset` refused because an object already sits at that path.
 *
 * Its own class rather than a message string, because #332 has to act on this
 * one differently from every other upload failure: the row it was about to
 * write already has bytes behind it, which for a content addressed path means
 * nothing needs uploading at all. Confirmed against the local stack: the
 * Storage API answers a colliding `upsert: false` upload with
 * `StorageApiError`, `statusCode: "409"`, `code: "KeyAlreadyExists"`.
 */
export class StagedAssetExistsError extends Error {
  constructor(readonly path: string) {
    super(`${path} already exists in the staging bucket`);
    this.name = "StagedAssetExistsError";
  }
}

/** Strips a leading slash, so a caller that writes `units/x.webp` and a
 *  caller that writes `/units/x.webp` address the same object. Mirrors
 *  `pathname` in `./blob`. */
function pathname(path: string): string {
  return path.replace(/^\/+/, "");
}

/** True for the one `StorageApiError` `putStagedAsset` has to tell apart from
 *  the rest: a conflicting key under `upsert: false`. Checked on `statusCode`
 *  rather than `code`, because `statusCode` is the HTTP status the Storage
 *  API actually returned (409, confirmed against the local stack) and is the
 *  more stable of the two: the S3-flavoured `code` field is documented
 *  upstream as `ResourceAlreadyExists` but the local stack answers
 *  `KeyAlreadyExists`. */
function isKeyExistsError(error: StorageApiError): boolean {
  return error.statusCode === "409";
}

/**
 * Write one object to the staging bucket, refusing to overwrite. Used for
 * everything content addressed: the path is the hash of the bytes, so a
 * caller that already sees a row with this path has nothing left to upload.
 *
 * Throws {@link StagedAssetExistsError} for that collision, and the raw
 * `StorageApiError` for anything else the Storage API refused the upload for
 * (wrong MIME type, over the bucket's size limit, and so on).
 */
export async function putStagedAsset(
  supabase: SupabaseClient,
  path: string,
  body: StagedAssetBody,
  contentType: string,
): Promise<void> {
  const key = pathname(path);
  const { error } = await supabase.storage.from(STAGED_PICTURES_BUCKET).upload(key, body, {
    contentType,
    upsert: false,
  });

  if (error) {
    if (error instanceof StorageApiError && isKeyExistsError(error)) {
      throw new StagedAssetExistsError(key);
    }
    throw error;
  }
}

/**
 * Write a game's logo or banner to the staging bucket, overwriting whatever
 * was at that path. The mirror of `putBlobGameImage` in `./blob`: the path is
 * deterministic (`games/BA/logo.webp`) rather than content addressed, so a
 * new upload is meant to replace the old bytes rather than collide with them.
 */
export async function putStagedGameImage(
  supabase: SupabaseClient,
  path: string,
  body: StagedAssetBody,
  contentType: string,
): Promise<void> {
  const { error } = await supabase.storage.from(STAGED_PICTURES_BUCKET).upload(pathname(path), body, {
    contentType,
    upsert: true,
  });

  if (error) throw error;
}

/**
 * Read one object's bytes back from the staging bucket, for moderation and
 * promotion. Throws the raw `StorageApiError` when the Storage API does not
 * answer with the bytes, which includes a path with nothing at it
 * (`code: "NoSuchKey"`, confirmed against the local stack).
 */
export async function downloadStagedAsset(supabase: SupabaseClient, path: string): Promise<Blob> {
  const { data, error } = await supabase.storage.from(STAGED_PICTURES_BUCKET).download(pathname(path));

  if (error) throw error;
  return data;
}

/**
 * Delete a batch of objects from the staging bucket, addressed by path.
 *
 * Safe to repeat: confirmed against the local stack, `remove()` does not
 * error on a path that is already gone, whether that is the only path asked
 * for or one of several. That is what lets promotion and the sweep delete
 * twice and have the second call be a no-op rather than a failure.
 *
 * Takes a list because every caller works in batches, and does nothing at
 * all with an empty one rather than making a round trip to delete nothing.
 */
export async function removeStagedAssets(supabase: SupabaseClient, paths: string[]): Promise<void> {
  if (paths.length === 0) return;

  const { error } = await supabase.storage.from(STAGED_PICTURES_BUCKET).remove(paths.map(pathname));

  if (error) throw error;
}
