import { NextResponse } from "next/server";
import { writeNav } from "@/lib/auth/nav";
import { createClient } from "@/lib/supabase/server";

/**
 * Writes `hub_nav` for a session that has none and returns it.
 *
 * The header asks this only when the browser holds a Supabase session and no
 * `hub_nav`, which is a session from before the cookie existed or one whose
 * cookie was cleared. Everybody else is drawn from cookies alone, so this runs
 * once per such session rather than once per page.
 *
 * POST, so nothing caches it and a crawler following links never reaches it.
 * Reading the session here also refreshes it, since a route handler can write
 * the rotated cookies.
 */
export async function POST() {
  const nav = await writeNav(await createClient());
  return NextResponse.json(nav, { headers: { "Cache-Control": "private, no-store" } });
}
