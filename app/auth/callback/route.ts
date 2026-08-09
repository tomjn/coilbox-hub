import { NextResponse, type NextRequest } from "next/server";
import { createClient } from "@/lib/supabase/server";

/**
 * Where Discord sends people back to. Exchanges the one time code for a session
 * and puts them where they were heading.
 */
export async function GET(request: NextRequest) {
  const url = new URL(request.url);
  const code = url.searchParams.get("code");
  // Only ever a path, never a full URL. An attacker who can choose where sign in
  // lands can bounce someone to a lookalike site carrying a real session.
  const raw = url.searchParams.get("next") ?? "/publish";
  const next = raw.startsWith("/") && !raw.startsWith("//") ? raw : "/publish";

  if (!code) {
    return NextResponse.redirect(new URL("/?error=no_code", url.origin));
  }

  const supabase = await createClient();
  const { error } = await supabase.auth.exchangeCodeForSession(code);

  if (error) {
    return NextResponse.redirect(new URL("/?error=sign_in_failed", url.origin));
  }

  return NextResponse.redirect(new URL(next, url.origin));
}
