/**
 * The one place that talks to Vercel Blob, the staging tier (issue #99).
 *
 * The store exists already: `coilbox-staging`, `store_eYugwJvmp953ayOg`, region
 * `iad1`, public access, linked to the `coilbox-hub` Vercel project. Nothing
 * here creates it and nobody needs to open the dashboard.
 *
 * ## The allowances, and why this file is a file
 *
 * Vercel does not publish the Hobby allowances. Read off the store dashboard on
 * 2026-08-14 they are 1 GB storage, 10 GB a month of data transfer, 10,000
 * simple operations and 2,000 advanced operations.
 *
 * Advanced is the one that binds. `put()`, `copy()` and `list()` all count
 * against it, so the ceiling is 2,000 uploads a month. Exceeding it removes
 * Blob access for 30 days. There is no overage billing, so it cannot be paid
 * through, and a month of the hub not accepting a single picture is not a bill,
 * it is an outage.
 *
 * That is why four rules exist, and why they are enforced by what this module
 * does and does not export rather than by a paragraph in a ticket:
 *
 * 1. Never call `list()`. Postgres already knows what is in the store, because
 *    every object here has a `public.asset` row. Not exported.
 * 2. Never call `head()` to check whether something exists. That is a metered
 *    simple operation answering a question the row answers for free. Not
 *    exported.
 * 3. Never bulk seed through Blob. 2,000 minimaps is one whole month of the
 *    advanced allowance. The seed writes straight to the durable tier, which is
 *    a git repository and costs nothing here. Nothing in this module is shaped
 *    for a batch.
 * 4. Do not browse the store in the Vercel dashboard while debugging. The
 *    dashboard lists blobs, and listing is an advanced operation. This is the
 *    one rule no code can enforce, so it is written down here and in the ESLint
 *    comment that bans the import elsewhere.
 *
 * Two things are free and worth knowing when weighing any of the above: cache
 * hits, and `del()`. The store is deliberately public, which also puts delivery
 * on the blob data transfer meter rather than on fast data transfer.
 *
 * `copy()` is absent for the same reason as `list()`: it is an advanced
 * operation, and content addressed paths mean the hub never needs to duplicate
 * an object under a second name.
 *
 * ## The token
 *
 * `BLOB_READ_WRITE_TOKEN` is set on the Vercel project and is server side. It
 * must never gain a `NEXT_PUBLIC_` prefix: anything so prefixed is inlined into
 * the browser bundle at build time, which would hand every visitor a write
 * token for the store. It is read here, in one place, and passed explicitly to
 * the SDK so a missing one fails with a message that names it.
 */

import { del, put } from "@vercel/blob";

/**
 * Where the staging tier serves from, the mirror of `DEFAULT_ASSET_CDN_BASE` in
 * `./cdn` for the durable tier. Reported by `vercel blob get-store
 * store_eYugwJvmp953ayOg`, and it is the store id lowercased with the `store_`
 * prefix dropped.
 *
 * A constant and not an environment variable, which is where this deliberately
 * differs from `./cdn`. The base is not configuration, it is the identity of
 * the store that `BLOB_READ_WRITE_TOKEN` already points at. Two independent
 * sources for one store is a way to get them out of step, and a base pointing
 * at a store the token cannot write to fails as a 404 on every image rather
 * than as an error. Moving to another store changes the token and this line
 * together.
 */
export const BLOB_TIER_BASE = "https://eyugwjvmp953ayog.public.blob.vercel-storage.com/";

export const BLOB_TOKEN_ERROR =
  "This deployment has not configured Vercel Blob. Set BLOB_READ_WRITE_TOKEN.";

/**
 * The advanced operations one Hobby store gets a month, and therefore the
 * number of uploads the hub can accept in one. Exported because the upload
 * route's quota check (#104) needs a number to check against, and the number
 * belongs next to the only code that spends it rather than inlined at the call
 * site.
 *
 * Nothing here counts operations. Counting needs shared state across serverless
 * invocations, which means Postgres, which means it belongs to whoever owns the
 * quota rows.
 */
export const BLOB_ADVANCED_OPERATIONS_PER_MONTH = 2000;

/**
 * What an upload body may be. Narrower than the SDK's `PutBody`, which also
 * accepts a Node `Readable`: a route handler has an `ArrayBuffer`, a `File`
 * (which is a `Blob`) or `request.body`, and offering a Node stream invites
 * code that only works off the edge.
 */
export type BlobAssetBody = ArrayBuffer | Blob | ReadableStream | string;

/**
 * The object key for a tier relative `asset.path`.
 *
 * Every entry point normalises through this, so a caller that writes
 * `units/x.webp` and a caller that writes `/units/x.webp` address the same
 * object. Without it a delete quietly misses the blob a put created, and the
 * store grows against a 1 GB allowance with nothing pointing at the leak.
 */
function pathname(path: string): string {
  return path.replace(/^\/+/, "");
}

/**
 * The absolute URL for a tier relative `asset.path` on the staging tier, the
 * mirror of `staticTierUrl` in `./cdn`.
 *
 * Costs nothing. It is string work, so the resolution order in #108 can ask for
 * a Blob URL as cheaply as it asks for a durable tier one, and neither rung of
 * the ladder touches the network to find out where something lives.
 *
 * Joined by concatenation for the same reason as `staticTierUrl`: `new URL`
 * would resolve a leading slash against the origin.
 */
export function blobTierUrl(path: string): string {
  return BLOB_TIER_BASE + pathname(path);
}

/**
 * The token, or an error naming it. Follows `requireSupabaseConfig` in
 * `lib/supabase/config.ts`: a missing variable should not reach the SDK as
 * `undefined` and throw from inside it (issue 54).
 *
 * Exported for the client direct upload path (#104), which calls
 * `handleUpload` from `@vercel/blob/client` rather than anything in this file
 * and would otherwise read the variable a second time. `@vercel/blob/client`
 * is deliberately not restricted by the ESLint rule, because it carries the
 * supported browser upload path and none of the metered lookups, but the token
 * still has exactly one reader.
 */
export function requireBlobToken(): string {
  const token = process.env.BLOB_READ_WRITE_TOKEN;
  if (!token) throw new Error(BLOB_TOKEN_ERROR);
  return token;
}

/**
 * Write one object to the staging tier. Returns its absolute URL.
 *
 * The only advanced operation this codebase makes, so it is the only function
 * with a per-call cost against 2,000 a month. Everything an upload can be
 * rejected for belongs before the call, not inside it: a rejected upload should
 * spend nothing (#104).
 *
 * The options are fixed rather than passed through, because each one is a
 * decision that has already been made and none of them varies per asset.
 *
 * - `access: "public"` is deliberate. It is what puts delivery on the blob data
 *   transfer meter instead of fast data transfer, and everything the hub stores
 *   here is a picture it intends to show.
 * - `addRandomSuffix: false` because the row stores the path, and a suffix the
 *   database never saw makes the object unreachable from it. This is the SDK
 *   default in v2 and is set anyway, since the whole scheme rests on it.
 * - `allowOverwrite: true` because paths are content addressed, so the same
 *   path is the same bytes and rewriting them changes nothing. Without it a
 *   retry after a partial failure throws and strands the row.
 *
 * `cacheControlMaxAge` is left at the SDK default of one month, which already
 * outlives the object: promotion (#111) moves anything approved out after seven
 * days. Asking for longer buys nothing.
 */
export async function putBlobAsset(
  path: string,
  body: BlobAssetBody,
  contentType: string,
): Promise<string> {
  const token = requireBlobToken();

  const result = await put(pathname(path), body, {
    access: "public",
    addRandomSuffix: false,
    allowOverwrite: true,
    contentType,
    token,
  });

  return result.url;
}

/**
 * Remove objects from the staging tier, addressed by tier relative path.
 *
 * Free: deletion is not metered as either kind of operation, which is what lets
 * promotion (#111) empty the store on a schedule without spending any of the
 * monthly allowance. The per minute rate limit still applies and a batch counts
 * per blob, so a large run should pace itself.
 *
 * Takes a list because the only caller works in batches, and does nothing at
 * all with an empty one rather than making a round trip to delete nothing.
 *
 * Deleting is safe to repeat and does not fail on an object that is already
 * gone, which is what lets promotion write the row first and delete second and
 * still be resumable.
 */
export async function deleteBlobAssets(paths: string[]): Promise<void> {
  if (paths.length === 0) return;

  await del(paths.map(pathname), { token: requireBlobToken() });
}
