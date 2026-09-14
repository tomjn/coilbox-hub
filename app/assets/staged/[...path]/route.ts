import { NextResponse } from "next/server";
import { fetchApprovedStagedPicture, type StagedPicture } from "@/lib/assets/bucket";
import { isAssetMime } from "@/lib/assets/path";
import { createAdminClient } from "@/lib/supabase/admin";
import { createAnonClient } from "@/lib/supabase/anon";

/**
 * An approved picture from the private staging bucket, for anybody (issue
 * #334). `lib/assets/bucket.ts` says why this is a route and what it serves.
 *
 * ## What everybody gets when it will not serve
 *
 * 404 with no body, for a pending row, a rejected row, a path no row names, and
 * an approved row whose object is gone. All four take the same response, so a
 * stranger holding a picture's bytes cannot use this to learn whether somebody
 * uploaded them.
 *
 * `no-store` on it, so a refusal is never cached. A pending picture approved a
 * minute later then shows at once, rather than when a cached 404 runs out.
 *
 * ## Caching an approval, and what that costs a later rejection
 *
 * The path is a hash of the bytes, so a served response is marked immutable for
 * a year. Vercel's CDN caches it and so does the visitor's browser.
 *
 * The cost is that a rejection after approval cannot recall copies already
 * handed out. The route refuses the next request that
 * reaches it, and the pages stop naming the URL as their caches refresh. But
 * the CDN keeps serving its copy until the next deployment, which is when
 * Vercel drops a deployment's cache, and a browser that already loaded the
 * picture keeps it. Accepted because Blob was no better: nothing deletes a
 * rejected row's object (`rejectPicture` in `lib/assets/queue.ts`), so a
 * rejected Blob picture stayed at its public URL for good. Redeploying is the
 * way to pull a picture out of the CDN in a hurry.
 */

const CACHE = "public, max-age=31536000, immutable";

function refused(status: 404 | 502): NextResponse {
  return new NextResponse(null, { status, headers: { "Cache-Control": "no-store" } });
}

export async function GET(
  _request: Request,
  ctx: RouteContext<"/assets/staged/[...path]">,
) {
  const { path } = await ctx.params;

  let picture: StagedPicture | null;
  try {
    picture = await fetchApprovedStagedPicture(createAnonClient(), createAdminClient(), path.join("/"));
  } catch {
    return refused(502);
  }
  if (!picture) return refused(404);

  return new NextResponse(picture.bytes, {
    headers: {
      // The same three as `app/moderation/assets/[id]/route.ts`, and for the
      // same reason. This is the hub's own origin, and an approved row's type
      // is still whatever the uploader declared. A type the browser will not
      // render as a document, a promise not to sniff past it, and a policy
      // that lets the bytes reach nothing if they are rendered as one anyway.
      "Content-Type": isAssetMime(picture.mime) ? picture.mime : "application/octet-stream",
      "X-Content-Type-Options": "nosniff",
      "Content-Security-Policy": "default-src 'none'; sandbox",
      "Cache-Control": CACHE,
      // The 3D map preview reads a height overlay pixel by pixel with
      // `crossOrigin = "anonymous"` (`components/mapTerrain.ts`), and the page
      // may be on a different host from `siteUrl()`. Blob and GitHub Pages both
      // answer this, and the bytes are public.
      "Access-Control-Allow-Origin": "*",
    },
  });
}
