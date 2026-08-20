import { NextResponse } from "next/server";
import { buildAssetHaveBody, parseAssetHaveBody } from "@/lib/api/assetHave";
import { corsPreflight, withCors } from "@/lib/api/cors";
import { apiError } from "@/lib/api/response";
import { fetchAssetSourceHashes } from "@/lib/assets/have";
import { createAdminClient } from "@/lib/supabase/admin";
import { authenticateBearer } from "@/lib/supabase/bearer";
import { SUPABASE_SERVICE_ROLE_ERROR } from "@/lib/supabase/config";

/**
 * The batch have check (issue #103). Coilbox calls this before it renders,
 * encodes or uploads anything, because most of the time the answer is that the
 * hub already has it.
 *
 * Answered entirely from `public.asset`. Nothing here touches Vercel Blob:
 * `head()` is a metered simple operation asking what the row already knows, and
 * `list()` spends the 2,000 advanced operations a month that uploads live on.
 * The comparison is on `source_hash`, over the raw archive bytes, never `hash`,
 * over the encoded ones, which legitimately differs between Coilbox releases
 * and would report every asset as changed after any encoder upgrade.
 *
 * ## Why this needs a token
 *
 * The useful answer covers pending and rejected rows. An upload that is still
 * in the queue is one the hub does not want again, and answering from approved
 * rows alone would have Coilbox re-upload it and spend an advanced operation on
 * bytes already sitting in the store. So this reads through the secret key
 * client, which bypasses the `asset_read_approved` policy.
 *
 * That means the answer carries something the read policy does not hand out:
 * that a row exists which the public cannot see. Hence a bearer token. It costs
 * the caller nothing, because a client that cannot sign in cannot upload and so
 * cannot act on anything but `have` anyway, and it keeps a 500 key batch out of
 * reach of anybody who has not got an account.
 *
 * What the answer does not carry is which of the three states a row is in. See
 * `resolveStatus` in `lib/api/assetHave.ts`: pending, approved and rejected all
 * mean the same thing to a caller deciding whether to upload, so naming them
 * would disclose the moderation queue for no decision it changes.
 */
export const OPTIONS = corsPreflight;

export async function POST(request: Request) {
  const auth = await authenticateBearer(request);
  if (!auth.ok) {
    return apiError(
      auth.reason === "missing"
        ? 'Send an access token as "Authorization: Bearer <token>".'
        : "That access token is not valid. Sign in again and use a fresh one.",
      401,
    );
  }

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return apiError("The request body must be JSON.", 400);
  }

  const parsed = parseAssetHaveBody(body);
  if (!parsed.ok) {
    return apiError(parsed.error, parsed.status);
  }

  // A deployment without the secret key cannot answer this at all, and the
  // proxy's configuration check only covers the two public variables. Without
  // this the client gets Next's generic HTML 500, which is the failure issue 54
  // was about.
  let admin;
  try {
    admin = createAdminClient();
  } catch {
    return apiError(SUPABASE_SERVICE_ROLE_ERROR, 503);
  }

  const lookup = await fetchAssetSourceHashes(
    admin,
    parsed.keys.map((key) => key.identity),
  );
  if (!lookup.ok) {
    return apiError("The asset index could not be read just now.", 503);
  }

  // Built here rather than through `apiJson`, which adds the same sixty second
  // cache header the gallery routes carry. This answer is a function of the
  // request body and goes stale the moment anything is uploaded or approved, and
  // a stale one either loses a picture or spends an upload. Nothing may hold it.
  return withCors(
    NextResponse.json(buildAssetHaveBody(parsed.keys, lookup.sourceHashes), {
      headers: { "Cache-Control": "no-store" },
    }),
  );
}
