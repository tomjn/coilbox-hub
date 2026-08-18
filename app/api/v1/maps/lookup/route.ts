import { NextResponse } from "next/server";
import { corsPreflight, withCors } from "@/lib/api/cors";
import { buildMapLookupBody, parseMapLookupBody } from "@/lib/api/mapLookup";
import { apiError } from "@/lib/api/response";
import { fetchMapFacts } from "@/lib/maps/lookup";
import { createAdminClient } from "@/lib/supabase/admin";
import { SUPABASE_SERVICE_ROLE_ERROR } from "@/lib/supabase/config";

/**
 * What the hub knows about a map (#188), which is the route the map catalog was
 * built for. A caller sends canonical map names and gets back the facts.
 *
 * Coilbox draws a battle lobby and a download screen for maps the player has
 * not installed, so it holds a name and nothing else. #171 gave that name a
 * picture and this gives it the facts.
 *
 * ## No picture in the answer
 *
 * Coilbox already calls `/api/v1/assets/pictures` for the minimap. Resolving a
 * tier here as well would put the tier logic in two routes, and the tier a
 * picture is served from changes on its own as `scripts/promote-assets.ts`
 * moves rows. Two calls, each with one job.
 *
 * ## Why this needs no token
 *
 * Everything in the answer describes a map page the hub is already serving in
 * public. `public.map` grants select to `anon`, `map_read_all` shows every row,
 * and there is nothing here a reader of `/map/[slug]` could not see. A lookup
 * that demanded an account would put a lobby's map name behind a sign in, which
 * is the rung of coilbox's ladder that has no account yet.
 *
 * The secret key is still used, and that is not a contradiction. It is there
 * for `public.asset_licence`, which `service_role` alone may read, and it is
 * what makes the licence gate below possible at all. Nothing about who is
 * asking changes a single field of the answer.
 *
 * ## A name the hub knows nothing about is null, not an error
 *
 * That is a 200 and the ordinary answer for most names while the catalog fills
 * up. The caller's next move is its own fallback, and an error would make the
 * ordinary case look like a fault. The same answer `/api/v1/assets/pictures`
 * gives for a picture it does not hold.
 *
 * A map the hub holds and may not publish answers the same null.
 * `lib/maps/lookup.ts` sets out why the catalog honours the licence decision
 * the pictures were already published under rather than making a second one:
 * a takedown is then one mechanism covering pictures and facts together.
 *
 * ## A read that fails is a 503 rather than a page of nulls
 *
 * The pictures route answers nulls when it cannot read, because a null there is
 * the placeholder the caller would draw anyway. A null here is a claim - the
 * hub has never heard of this map - and a client that acted on a claim the hub
 * could not make would stop asking about a map the catalog holds. So a failed
 * read says so, the same as `/api/v1/maps/have`.
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

  const parsed = parseMapLookupBody(body);
  if (!parsed.ok) {
    return apiError(parsed.error, parsed.status);
  }

  // A deployment without the secret key cannot read the licence table at all,
  // and the proxy's configuration check only covers the two public variables.
  // Without this the client gets Next's generic HTML 500, which is the failure
  // issue 54 was about.
  let admin;
  try {
    admin = createAdminClient();
  } catch {
    return apiError(SUPABASE_SERVICE_ROLE_ERROR, 503);
  }

  // Asked once, answered per position. A caller's list is a screen rather than
  // an inventory, so the same map can appear on several rows of it, and the
  // answer is the same for each. The database is asked about the distinct names
  // and the body below puts an answer against every name the request listed.
  const lookup = await fetchMapFacts(admin, [...new Set(parsed.names)]);
  if (!lookup.ok) {
    return apiError("The map catalog could not be read just now.", 503);
  }

  // Built here rather than through `apiJson`, which adds the sixty second cache
  // header the gallery routes carry. This answer is a function of the request
  // body rather than of the URL, so anything holding it by URL would serve one
  // caller's maps to another.
  return withCors(
    NextResponse.json(buildMapLookupBody(parsed.names, lookup.facts), {
      headers: { "Cache-Control": "no-store" },
    }),
  );
}
