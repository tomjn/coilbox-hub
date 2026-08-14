import { NextResponse } from "next/server";
import { isAssetMime } from "@/lib/assets/path";
import { fetchAssetObject } from "@/lib/assets/queue";
import { createAdminClient } from "@/lib/supabase/admin";
import { createClient } from "@/lib/supabase/server";

/**
 * The bytes of one asset, for a moderator and nobody else (issue #114).
 *
 * ## Why the grid does not just link at the store
 *
 * A pending upload is in a public Blob store, reachable the moment `put()`
 * returns, and the only thing keeping it out of sight is that its path carries a
 * suffix nobody outside the hub can derive (#131). Putting that path in the
 * page's markup would hand the browser the one secret the queue rests on, for a
 * few hundred rows at a time. It would then be in the page source, the RSC
 * payload, the back/forward cache and whatever the browser is running, and there
 * is no taking it back: a leaked path cannot be rotated without rewriting the
 * object, which is the thing being protected.
 *
 * So the path never leaves the server. The browser gets this URL instead, which
 * names a row rather than an object, and the hub reads the path and fetches the
 * bytes itself. Access is decided per request rather than handed out once, so
 * somebody who stops being a moderator stops seeing pictures on their next
 * request rather than keeping a set of working URLs.
 *
 * ## What everybody else gets
 *
 * 404, whether they are signed out, signed in without `can_moderate`, or a
 * moderator asking for a row that does not exist. The same reasoning as
 * `app/moderation/page.tsx`: whether this row exists is not something a stranger
 * needs to learn, and a 403 on a real id and a 404 on a made up one is a
 * membership oracle over the queue.
 */

/**
 * A private cache is the moderator's own browser and nothing in between, which
 * is what `private` is for: a shared cache, Vercel's included, must not hold a
 * pending picture. Without a max-age a reload refetches every thumbnail from the
 * store, which spends Blob data transfer to show a picture that has not changed.
 *
 * The one thing it costs: for five minutes after somebody stops being a
 * moderator, their own browser can still show them a picture it already
 * fetched while they were one. Verified, and accepted, because that browser was
 * handed those bytes legitimately and could have kept them anyway. Every request
 * that actually reaches the hub is refused immediately.
 */
const CACHE = "private, max-age=300";

export async function GET(
  _request: Request,
  ctx: RouteContext<"/moderation/assets/[id]">,
) {
  const supabase = await createClient();
  const { data: allowed } = await supabase.rpc("is_moderator");
  if (!allowed) return new NextResponse(null, { status: 404 });

  const { id } = await ctx.params;
  const object = await fetchAssetObject(createAdminClient(), id);
  if (!object) return new NextResponse(null, { status: 404 });

  const upstream = await fetch(object.url);
  if (!upstream.ok || !upstream.body) {
    return new NextResponse(null, { status: 502 });
  }

  return new NextResponse(upstream.body, {
    headers: {
      // The row's own type, but only when it is one the hub stores at all. A
      // pending row is whatever an untrusted client declared, and this response
      // comes from the hub's own origin, so a row claiming `text/html` would be
      // stored cross site scripting against the one account that can approve
      // things. The three headers below close that from three directions: a
      // type the browser will not render as a document, a promise not to sniff
      // past it, and a policy that lets the bytes reach nothing if it somehow
      // is rendered as one.
      "Content-Type": isAssetMime(object.mime) ? object.mime : "application/octet-stream",
      "X-Content-Type-Options": "nosniff",
      "Content-Security-Policy": "default-src 'none'; sandbox",
      "Cache-Control": CACHE,
    },
  });
}
