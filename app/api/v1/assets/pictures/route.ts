import { NextResponse } from "next/server";
import { buildAssetPicturesBody, parseAssetPicturesBody } from "@/lib/api/assetPictures";
import { corsPreflight, withCors } from "@/lib/api/cors";
import { apiError } from "@/lib/api/response";
import { fetchHeldAssets } from "@/lib/assets/resolve";
import { createClient } from "@/lib/supabase/server";

/**
 * The batch picture lookup (issue #171). A caller sends identities and gets back
 * which of them the hub has a picture of, and where each one is.
 *
 * This is the question `/api/v1/assets/have` is not. That route decides whether
 * to upload: it wants a `source_hash` per key, it answers without a path, and it
 * needs a bearer token because its answer covers rows the public cannot see.
 * This one decides what to show, so it takes no hash, answers with a path, and
 * needs no token.
 *
 * ## Why there is no `source_hash` on a key
 *
 * The caller is asking about a map it has not got installed. It does not hold
 * the archive, so it cannot hash it, and the identity plus the variant is all it
 * can offer. That is also why the answer has to carry a path at all: `asset.path`
 * is the sha256 of the encoded bytes plus a random suffix for anything still in
 * the staging tier, so nothing without the bytes can derive it.
 *
 * ## Why this needs no token
 *
 * It reads through the anonymous client, so `asset_read_approved` in
 * `20260814180000_asset_access.sql` is underneath the answer: `anon` sees
 * approved rows and nothing else. `fetchHeldAssets` filters on `moderation`
 * anyway and `resolveAsset` drops anything unapproved before it looks at a tier,
 * so three separate things would have to fail before a pending row's path could
 * reach anybody. Everything this hands out is a picture the hub is already
 * serving on its own item pages.
 *
 * Which is what makes the missing token safe rather than convenient. A desktop
 * client browsing the gallery has no account, browsing needs none anywhere else
 * in this API, and a picture lookup that demanded one would put every hub rung
 * of coilbox's ladder behind a sign in.
 *
 * ## Nothing may cache the answer
 *
 * `scripts/promote-assets.ts` moves an approved row from the staging tier to the
 * durable one and deletes the staging object behind it, so a held path stops
 * working. A cached answer serves a 404 in place of a picture, which is the one
 * failure this route exists to prevent, so it is built here rather than through
 * `apiJson` and its sixty second cache header.
 *
 * ## A lookup that fails answers "no picture" rather than 503
 *
 * `fetchHeldAssets` drops a chunk it could not read, so a database the hub
 * cannot reach comes back as nulls. That is deliberate and is the same answer
 * the website's own item pages take: the honest render for "the hub does not
 * know what it holds" is the caller's placeholder, which is what it would draw
 * anyway. It is only safe because nothing may hold the answer, which is what the
 * header above is for.
 */
export const dynamic = "force-dynamic";

export const OPTIONS = corsPreflight;

export async function POST(request: Request) {
  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return apiError("The request body must be JSON.", 400);
  }

  const parsed = parseAssetPicturesBody(body);
  if (!parsed.ok) {
    return apiError(parsed.error, parsed.status);
  }

  const supabase = await createClient();
  const held = await fetchHeldAssets(supabase, parsed.identities);

  return withCors(
    NextResponse.json(buildAssetPicturesBody(parsed.identities, held), {
      headers: { "Cache-Control": "no-store" },
    }),
  );
}
