import { NextResponse, type NextRequest } from "next/server";
import { createClient } from "@/lib/supabase/server";
import { signInProvider } from "@/lib/supabase/signInProvider";

/**
 * Where an OAuth provider sends people back to. Exchanges the one time code for a session
 * and puts them where they were heading.
 */
export async function GET(request: NextRequest) {
  const url = new URL(request.url);
  const code = url.searchParams.get("code");
  // Only ever a path, never a full URL. An attacker who can choose where sign in
  // lands can bounce someone to a lookalike site carrying a real session.
  const raw = url.searchParams.get("next") ?? "/publish";
  const next = raw.startsWith("/") && !raw.startsWith("//") ? raw : "/publish";

  // Checked against the list, since it came in on the query string.
  const provider = signInProvider(url.searchParams.get("provider"));

  // Linking another way in from /account (issue #435) goes back there either
  // way. A provider or Supabase refusing the link arrives here with no code and
  // an `error_code`, such as an identity already on another account.
  if (url.searchParams.get("link") && provider) {
    const failed = (reason: string | null) =>
      NextResponse.redirect(
        new URL(
          `/account?link_failed=${provider}${reason ? `&link_reason=${encodeURIComponent(reason)}` : ""}#sign-in-methods`,
          url.origin,
        ),
      );
    if (!code) return failed(url.searchParams.get("error_code"));
    const supabase = await createClient();
    const { error } = await supabase.auth.exchangeCodeForSession(code);
    if (error) return failed(error.code ?? null);
    return NextResponse.redirect(new URL("/account#sign-in-methods", url.origin));
  }

  if (!code) {
    return NextResponse.redirect(new URL("/?error=no_code", url.origin));
  }

  const supabase = await createClient();
  const { error } = await supabase.auth.exchangeCodeForSession(code);

  if (error) {
    // Named so somebody with two providers linked knows which one to try
    // instead.
    const failed = provider ? `/?error=sign_in_failed&provider=${provider}` : "/?error=sign_in_failed";
    return NextResponse.redirect(new URL(failed, url.origin));
  }

  return NextResponse.redirect(new URL(next, url.origin));
}
