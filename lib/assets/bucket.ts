import { StorageApiError, type SupabaseClient } from "@supabase/supabase-js";
import { siteUrl } from "@/lib/site";
import { staticTierUrl } from "./cdn";
import { downloadStagedAsset } from "./staging";

/**
 * How an approved picture in the private staging bucket reaches a browser
 * before promotion moves it to the durable tier (issue #334).
 *
 * The bucket has no public URL, so the hub serves the bytes itself at
 * `/assets/staged/<path>`, where `<path>` is the row's own content addressed
 * `asset.path`. That route is `app/assets/staged/[...path]/route.ts`.
 *
 * ## Why a route and not a signed URL
 *
 * Many pages that show pictures are cached with `"use cache"`, and a signed URL
 * held in a cached page keeps being handed out after it expires. That fails as
 * a broken image with nothing logged anywhere. A route URL does not expire, and
 * because the path is a hash of the bytes, the same URL always means the same
 * bytes. So the response is cached as immutable, and most views are answered by
 * Vercel's CDN without reaching the function or Supabase.
 *
 * ## What it will serve
 *
 * Only a row with `tier = 'bucket'` and `moderation = 'approved'`, the same
 * test `resolveAsset` in `./resolve` applies before it hands a page a URL, and
 * read the same way: with the anonymous client, so `asset_read_approved` is a
 * second layer under the filter. Pending, rejected and missing all come back
 * as no row from one query, so the route cannot tell them apart and neither can
 * anybody probing it with a hash they derived from bytes they hold.
 *
 * Nothing about `public.asset_licence` is checked, because `resolveAsset` checks
 * nothing about it either. That gate decides which map facts and pages exist,
 * not which approved pictures may be shown (`lib/gallery/cardPictures.ts`).
 *
 * Two rows can share a bucket path, because two units in one game can share a
 * picture and the path has no suffix. The route serves the bytes if any of
 * them is approved, which is what `resolveAsset` already does for that row's
 * page.
 *
 * ## After promotion
 *
 * Promotion (#335) moves a row to `static` at the same path, because a bucket
 * path is already the content addressed durable path. A page cached before the
 * move can keep naming this route for a while after it. So an approved `static`
 * row at the path is answered with a redirect to the durable tier, which is
 * safe to cache for good because both URLs name the same bytes. It is checked
 * before a bucket row, so a promoted picture is not read out of the bucket
 * while its copy there waits to be deleted.
 */

/** Where the route lives, relative to the site root, with the trailing slash. */
export const BUCKET_ROUTE_PREFIX = "/assets/staged/";

/**
 * The absolute URL for a bucket row's tier relative `asset.path`.
 *
 * Absolute rather than root relative, because `/api/v1/assets/pictures` hands
 * this to the desktop client, which has no page to resolve it against.
 */
export function bucketTierUrl(path: string): string {
  return siteUrl() + BUCKET_ROUTE_PREFIX + path.replace(/^\/+/, "");
}

/** One approved picture's bytes, and the type the row declares for them. */
export interface StagedPicture {
  bytes: Blob;
  mime: string;
}

/** An approved picture promotion has moved, and where the durable tier serves
 *  it. */
export interface PromotedPicture {
  promoted: string;
}

/**
 * The bytes at a bucket path, only when an approved bucket row names it. Null
 * otherwise, and null too when the row exists but the object does not, so the
 * route answers every refusal the same way. When an approved row at the path
 * has been promoted, the durable tier URL instead.
 *
 * `anon` reads the row and must be the anonymous client (`lib/supabase/anon.ts`).
 * `admin` reads the bucket, which only the secret key can.
 *
 * Throws when the database or the bucket fails for any other reason, and the
 * route turns that into a 502 without the error's own detail. A failure only
 * reaches the bucket after an approved row was found, so it tells a caller
 * nothing a page does not already publish.
 */
export async function fetchApprovedStagedPicture(
  anon: SupabaseClient,
  admin: SupabaseClient,
  path: string,
): Promise<StagedPicture | PromotedPicture | null> {
  // `static` sorts after `bucket`, so descending puts a promoted row first.
  const { data, error } = await anon
    .from("asset")
    .select("mime, tier")
    .eq("path", path)
    .in("tier", ["bucket", "static"])
    .eq("moderation", "approved")
    .order("tier", { ascending: false })
    .limit(1);

  if (error) throw error;

  const row = (data as { mime: string; tier: string }[] | null)?.[0];
  if (!row) return null;
  if (row.tier === "static") return { promoted: staticTierUrl(path) };

  try {
    return { bytes: await downloadStagedAsset(admin, path), mime: row.mime };
  } catch (thrown) {
    if (thrown instanceof StorageApiError && thrown.statusCode === "404") return null;
    throw thrown;
  }
}
