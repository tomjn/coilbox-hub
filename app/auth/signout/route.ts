import { NextResponse, type NextRequest } from "next/server";
import { clearNav } from "@/lib/auth/nav";
import { createClient } from "@/lib/supabase/server";

/** POST only, so a link somebody pastes cannot sign you out. */
export async function POST(request: NextRequest) {
  const supabase = await createClient();
  await supabase.auth.signOut();
  await clearNav();
  return NextResponse.redirect(new URL("/", request.url), { status: 303 });
}
