import { NextResponse, type NextRequest } from "next/server";
import { stepUpPath } from "@/lib/access/secondFactor";
import { createClient } from "@/lib/supabase/server";
import { signInProvider } from "@/lib/supabase/signInProvider";

const ACCOUNT_SECTION = "/account#sign-in-methods";

/**
 * Adds another way to sign in to the signed in account (issue #435), from a
 * plain form post the same way `app/auth/signin/route.ts` starts sign in.
 *
 * `linkIdentity` hands back the provider's URL rather than sending the browser
 * there, and leaves its PKCE verifier in a cookie for
 * `app/auth/callback/route.ts`, which the `link` flag tells to come back to the
 * account page with any refusal.
 *
 * An account with an authenticator has to have entered its code this session.
 * Otherwise a stolen session could link a way in that outlives it.
 */
export async function POST(request: NextRequest) {
  const form = await request.formData();
  const origin = request.nextUrl.origin;

  const provider = signInProvider(form.get("provider")?.toString());
  if (!provider) {
    return new NextResponse("Unknown sign in provider", { status: 400 });
  }

  const supabase = await createClient();
  const { data: level } = await supabase.auth.mfa.getAuthenticatorAssuranceLevel();
  // No current level means no session, so nothing to link to.
  if (!level?.currentLevel) return NextResponse.redirect(new URL("/publish", origin), 303);
  if (level.nextLevel === "aal2" && level.currentLevel !== "aal2") {
    return NextResponse.redirect(new URL(stepUpPath(ACCOUNT_SECTION), origin), 303);
  }

  const { data, error } = await supabase.auth.linkIdentity({
    provider,
    options: {
      redirectTo: `${origin}/auth/callback?next=${encodeURIComponent("/account")}&provider=${provider}&link=1`,
      skipBrowserRedirect: true,
    },
  });
  if (error || !data.url) {
    const reason = error?.code ? `&link_reason=${encodeURIComponent(error.code)}` : "";
    return NextResponse.redirect(
      new URL(`/account?link_failed=${provider}${reason}#sign-in-methods`, origin),
      303,
    );
  }

  return NextResponse.redirect(data.url, 303);
}
