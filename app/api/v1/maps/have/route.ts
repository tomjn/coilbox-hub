import { NextResponse } from "next/server";
import { corsPreflight, withCors } from "@/lib/api/cors";
import { buildMapHaveBody, parseMapHaveBody } from "@/lib/api/mapHave";
import { apiError } from "@/lib/api/response";
import { fetchMapCatalogState } from "@/lib/maps/have";
import { createAdminClient } from "@/lib/supabase/admin";
import { authenticateBearer } from "@/lib/supabase/bearer";
import { SUPABASE_SERVICE_ROLE_ERROR } from "@/lib/supabase/config";

/**
 * The batch have check for facts (#186), the same gate `/api/v1/assets/have`
 * gives pictures. A coilbox install with three thousand maps has three thousand
 * catalog entries it could submit and the hub already holds almost all of them,
 * so it asks first and sends what is left.
 *
 * Answered entirely from `public.map`, on `source_hash` and `catalog_version`.
 * `lib/api/mapHave.ts` holds the rule that turns those two into a status, and it
 * is not the asset rule: a matching hash at a newer client version is `changed`,
 * because the same archive read by a newer extractor is a better entry the hub
 * wants.
 *
 * ## Why this needs a token
 *
 * Not for the reason the asset check needs one. That answer covers pending and
 * rejected rows and therefore discloses something the read policy withholds.
 * There is no equivalent here: `public.map` grants select to `anon` and
 * `map_read_all` shows every row, so nothing in this answer is a secret. The
 * public read route for the facts themselves is `/api/v1/maps/lookup`, which is a
 * different question.
 *
 * The token is here because of what the route is for. It exists to decide whether
 * to write, so its caller is one that can write, and a caller that cannot sign in
 * cannot act on anything but `have` anyway. It also keeps a 500 key batch, and the
 * database work behind it, out of reach of anybody without an account.
 *
 * The consent to send facts at all is `hub.assetUploads`, coilbox's existing
 * switch, and the hub adds no second one. A user who agreed to send what coilbox
 * extracted from their archives agreed to this too, and two switches for one
 * decision is a worse experience than one. The gate is the client's to hold: the
 * hub cannot know whether a request it is answering was consented to, so there is
 * nothing here to check and nothing to add.
 */
export const dynamic = "force-dynamic";

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

  const parsed = parseMapHaveBody(body);
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

  const lookup = await fetchMapCatalogState(
    admin,
    parsed.keys.map((key) => key.mapName),
  );
  if (!lookup.ok) {
    return apiError("The map catalog could not be read just now.", 503);
  }

  // Built here rather than through `apiJson`, which adds the same sixty second
  // cache header the gallery routes carry. This answer is a function of the
  // request body and goes stale the moment anything is submitted, and a stale one
  // either loses a map's facts or asks for facts the hub already has. Nothing may
  // hold it.
  return withCors(
    NextResponse.json(buildMapHaveBody(parsed.keys, lookup.held), {
      headers: { "Cache-Control": "no-store" },
    }),
  );
}
