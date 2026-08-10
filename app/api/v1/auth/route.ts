import { NextResponse } from "next/server";
import { corsPreflight, withCors } from "@/lib/api/cors";
import { apiError } from "@/lib/api/response";
import { buildAuthBody } from "@/lib/api/auth";

/**
 * Tells a desktop client which Supabase project to run its Discord PKCE
 * sign-in flow against, and with which publishable key. Coilbox has exactly
 * one configurable address, the hub, and everything else hangs off it - so
 * rather than a second constant baked into the binary (which would sign
 * someone in against this project even when pointed at their own hub, with
 * nothing on screen explaining why), a client discovers both values from
 * here.
 *
 * These values change close to never, unlike a gallery listing, so this
 * builds its own response rather than going through `apiJson`'s 60 second
 * default: a client hits this on every sign-in, and there is no reason to
 * make it, or a CDN in front of it, re-fetch something this static every
 * minute.
 *
 * If a deployment is missing either environment variable, this hands back a
 * 503 rather than a body with an `undefined` field: a client that gets
 * `"supabase_url": undefined` has nowhere useful to go and nothing on
 * screen to explain why, whereas a 503 is a shape every HTTP client already
 * knows how to surface as "try again later".
 */
export const dynamic = "force-dynamic";

export const OPTIONS = corsPreflight;

export async function GET() {
  const result = buildAuthBody();
  if (!result.ok) {
    return apiError("This deployment has not configured Supabase sign-in.", 503);
  }

  return withCors(
    NextResponse.json(result.body, {
      headers: {
        // A day: long enough that a value which changes close to never is
        // not re-fetched on every sign-in, short enough that rotating the
        // Supabase project does not need a cache-busting story to reach
        // clients holding onto a stale response.
        "Cache-Control": "public, max-age=86400, s-maxage=86400",
      },
    }),
  );
}
