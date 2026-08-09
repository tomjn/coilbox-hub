import { createServerClient } from "@supabase/ssr";
import { NextResponse, type NextRequest } from "next/server";

/**
 * Refreshes the session on every request and writes the rotated cookies onto the
 * response. Without this a signed in visitor looks signed out to the server after
 * their access token expires, and publishing fails for no visible reason.
 */
export async function middleware(request: NextRequest) {
  let response = NextResponse.next({ request });

  const supabase = createServerClient(
    process.env.NEXT_PUBLIC_SUPABASE_URL!,
    process.env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY!,
    {
      cookies: {
        getAll() {
          return request.cookies.getAll();
        },
        setAll(written) {
          for (const { name, value } of written) {
            request.cookies.set(name, value);
          }
          response = NextResponse.next({ request });
          for (const { name, value, options } of written) {
            response.cookies.set(name, value, options);
          }
        },
      },
    },
  );

  // Calling getUser is what performs the refresh. Do not remove it.
  await supabase.auth.getUser();

  return response;
}

export const config = {
  matcher: [
    // Everything except static assets and the generated OpenGraph images, which
    // never need a session and would only pay the round trip.
    "/((?!_next/static|_next/image|favicon.ico|.*\\.(?:svg|png|jpg|jpeg|gif|webp)$|opengraph-image).*)",
  ],
};
