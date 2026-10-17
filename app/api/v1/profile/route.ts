import { NextResponse } from "next/server";
import { corsPreflight, withCors } from "@/lib/api/cors";
import { apiError } from "@/lib/api/response";
import { fetchDisplayName } from "@/lib/author";
import { authenticateBearer } from "@/lib/supabase/bearer";

/**
 * The signed in account's display name (#439), for the desktop client to show
 * in place of a name it builds from provider metadata. Read from
 * `public.profile` with the caller's own token, the same row the website header
 * shows.
 *
 * `display_name` is null when the account has no profile row, and the client
 * decides what to show then.
 *
 * The answer belongs to whoever sent the token, so it is `private, no-store`.
 * `apiJson` would add a public cache header, and a CDN holding this would hand
 * one person's name to the next caller.
 */
export const OPTIONS = corsPreflight;

export async function GET(request: Request) {
  const auth = await authenticateBearer(request);
  if (!auth.ok) {
    return apiError(
      auth.reason === "missing"
        ? 'Send an access token as "Authorization: Bearer <token>".'
        : "That access token is not valid. Sign in again and use a fresh one.",
      401,
    );
  }

  const displayName = await fetchDisplayName(auth.supabase, auth.user.id);

  return withCors(
    NextResponse.json(
      { display_name: displayName },
      { headers: { "Cache-Control": "private, no-store" } },
    ),
  );
}
