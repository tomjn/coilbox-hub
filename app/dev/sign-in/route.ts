import { createClient as createAdminClient } from "@supabase/supabase-js";
import { NextResponse, type NextRequest } from "next/server";
import { isDevSignInEnabled } from "@/lib/supabase/loopback";
import { createClient } from "@/lib/supabase/server";

// Discord shaped enough for lib/author.ts and current_author_name() (see
// supabase/migrations/20260809190500_item_insert_trust.sql) to derive a name
// instead of falling through to "Unknown" - the same shape the RLS test
// fixture in supabase/tests/item_rls.test.sql uses for the same reason.
const DEV_USER_EMAIL = "dev@coilbox.local";
const DEV_USER_METADATA = { full_name: "Dev User" };

/**
 * Signs the browser in without Discord, which cannot complete its OAuth
 * callback against the local stack (see issue #34). One click, or one
 * `curl -c`, gets the same real session the Discord callback would leave.
 *
 * This is an authentication bypass living in the route tree, so it is guarded
 * twice and either guard failing means the route behaves as though it does
 * not exist. A production deploy is never in development, and even if it
 * somehow were, its Supabase URL is never loopback, so a stray deploy carries
 * something inert rather than something dangerous. The service role key this
 * needs lives only in .env.development.local, which is gitignored, so a
 * machine without that file cannot use this route even if both guards
 * somehow passed.
 */
export async function GET(request: NextRequest) {
  if (!isDevSignInEnabled()) {
    return new NextResponse(null, { status: 404 });
  }
  const supabaseUrl = process.env.NEXT_PUBLIC_SUPABASE_URL!;

  const admin = createAdminClient(supabaseUrl, process.env.SUPABASE_SERVICE_ROLE_KEY!, {
    auth: { autoRefreshToken: false, persistSession: false },
  });

  // createUser() first, rather than leaving creation to generateLink(), because
  // generateLink() creating a brand new user and generating its magic link in
  // the same call is not consistent: verifyOtp on that same first token
  // intermittently comes back "invalid or has expired" against the local
  // stack. Creating the user up front and generating the link as a second,
  // separate call against a user that already exists does not have that race.
  // email_exists is the expected outcome on every visit after the first, and
  // is what makes this reuse one account rather than minting a new one.
  const { error: createError } = await admin.auth.admin.createUser({
    email: DEV_USER_EMAIL,
    email_confirm: true,
    user_metadata: DEV_USER_METADATA,
  });
  if (createError && createError.code !== "email_exists") {
    return NextResponse.json({ error: createError.message }, { status: 500 });
  }

  const { data, error: linkError } = await admin.auth.admin.generateLink({
    type: "magiclink",
    email: DEV_USER_EMAIL,
  });
  if (linkError) {
    return NextResponse.json({ error: linkError.message }, { status: 500 });
  }

  // verifyOtp on a non-admin client is what turns the generated link into a
  // real auth.sessions row and writes the session cookie @supabase/ssr reads,
  // the same way app/auth/callback/route.ts turns a Discord code into one.
  const supabase = await createClient();
  const { error: verifyError } = await supabase.auth.verifyOtp({
    token_hash: data.properties.hashed_token,
    type: "magiclink",
  });
  if (verifyError) {
    return NextResponse.json({ error: verifyError.message }, { status: 500 });
  }

  // Same sanitisation as app/auth/callback/route.ts: only ever a path, so
  // this cannot be pointed at a lookalike site carrying a real session.
  const url = new URL(request.url);
  const raw = url.searchParams.get("next") ?? "/publish";
  const next = raw.startsWith("/") && !raw.startsWith("//") ? raw : "/publish";

  return NextResponse.redirect(new URL(next, request.url));
}
