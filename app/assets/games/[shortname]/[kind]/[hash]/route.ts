import { NextResponse } from "next/server";
import { fetchGameArt, type PromotedGameArt, type StagedGameArt } from "@/lib/games/art";
import { createAdminClient } from "@/lib/supabase/admin";
import { createAnonClient } from "@/lib/supabase/anon";

/**
 * A game's logo or banner from the staging bucket, before promotion (issue
 * #345). `lib/games/art.ts` says why the URL names the hash and what it serves.
 *
 * Refusals are an empty 404 with `no-store`, the same as
 * `app/assets/staged/[...path]/route.ts`. A hash nobody has uploaded yet may be
 * a moment away from being written to the row, so a cached refusal would hide
 * it.
 */

const CACHE = "public, max-age=31536000, immutable";
const HASH = /^[0-9a-f]{64}$/;

function refused(status: 404 | 502): NextResponse {
  return new NextResponse(null, { status, headers: { "Cache-Control": "no-store" } });
}

export async function GET(
  _request: Request,
  ctx: RouteContext<"/assets/games/[shortname]/[kind]/[hash]">,
) {
  const { shortname, kind, hash } = await ctx.params;
  if ((kind !== "logo" && kind !== "banner") || !HASH.test(hash)) return refused(404);

  let art: StagedGameArt | PromotedGameArt | null;
  try {
    art = await fetchGameArt(createAnonClient(), createAdminClient(), shortname, kind, hash);
  } catch {
    return refused(502);
  }
  if (!art) return refused(404);

  // Promoted since the page naming this URL was cached. Temporary and never
  // cached, because the durable path is overwritten by the next promoted
  // upload, and this hash must not end up pointing at those bytes.
  if ("promoted" in art) {
    return new NextResponse(null, {
      status: 307,
      headers: {
        Location: art.promoted,
        "Cache-Control": "no-store",
        "Access-Control-Allow-Origin": "*",
      },
    });
  }

  return new NextResponse(art.bytes, {
    headers: {
      // The same headers as the staged picture route, for the same reasons: the
      // hub's own origin, a type the browser renders only as an image, and a
      // policy that lets the bytes reach nothing if opened as a document.
      "Content-Type": art.mime,
      "X-Content-Type-Options": "nosniff",
      "Content-Security-Policy": "default-src 'none'; sandbox",
      "Cache-Control": CACHE,
      "Access-Control-Allow-Origin": "*",
    },
  });
}
