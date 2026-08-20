import { NextResponse, type NextRequest } from "next/server";
import { isDevSignInEnabled } from "@/lib/supabase/loopback";
import { createClient } from "@/lib/supabase/server";
import { signInNext } from "@/lib/supabase/signInNext";

/**
 * Starts Discord sign in from a plain form post.
 *
 * The header and the publish page used to start it in the browser, which meant
 * every page shipped supabase-js for one button. A form post needs no script, so
 * sign in works with scripting off the way sign out already did, and the bundle
 * loses its largest chunk.
 *
 * The PKCE verifier lands in a cookie through the server client, which is the
 * same store `app/auth/callback/route.ts` reads it back from.
 *
 * Discord cannot complete its callback against a local Supabase stack (issue
 * #34), so in development this sends the browser to `app/dev/sign-in/route.ts`
 * instead, which applies the same guard before serving anything.
 */
export async function POST(request: NextRequest) {
  const form = await request.formData();
  const origin = request.nextUrl.origin;
  const next = signInNext(
    form.get("next")?.toString() ?? null,
    request.headers.get("referer"),
    origin,
  );

  if (isDevSignInEnabled()) {
    return NextResponse.redirect(
      new URL(`/dev/sign-in?next=${encodeURIComponent(next)}`, origin),
      303,
    );
  }

  const supabase = await createClient();
  const { data, error } = await supabase.auth.signInWithOAuth({
    provider: "discord",
    options: {
      redirectTo: `${origin}/auth/callback?next=${encodeURIComponent(next)}`,
      skipBrowserRedirect: true,
    },
  });
  if (error || !data.url) {
    return NextResponse.redirect(new URL("/?error=sign_in_failed", origin), 303);
  }

  return NextResponse.redirect(data.url, 303);
}
