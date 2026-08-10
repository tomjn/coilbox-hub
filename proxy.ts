import { createServerClient } from "@supabase/ssr";
import { NextResponse, type NextRequest } from "next/server";
import { apiError } from "@/lib/api/response";
import { getSupabaseConfig, SUPABASE_CONFIG_ERROR } from "@/lib/supabase/config";

/**
 * Refreshes the session on every request and writes the rotated cookies onto the
 * response. Without this a signed in visitor looks signed out to the server after
 * their access token expires, and publishing fails for no visible reason.
 *
 * This matcher covers nearly every route in the app (see `config` below), so
 * it is also the one place that catches a deployment missing its Supabase
 * configuration before any page or route handler runs. Without this check,
 * `createServerClient` threw here with an `undefined` URL and every visitor,
 * API client included, got Next's generic HTML 500 page (issue 54). An API
 * client gets a JSON body it can act on instead. A browser gets plain text
 * explaining the deployment is broken, rather than a page that quietly limps
 * along.
 */
export async function proxy(request: NextRequest) {
  const config = getSupabaseConfig();
  if (!config.ok) {
    if (request.nextUrl.pathname.startsWith("/api/")) {
      return apiError(SUPABASE_CONFIG_ERROR, 503);
    }
    return new NextResponse(SUPABASE_CONFIG_ERROR, {
      status: 500,
      headers: { "Content-Type": "text/plain; charset=utf-8" },
    });
  }

  let response = NextResponse.next({ request });

  const supabase = createServerClient(config.config.url, config.config.publishableKey, {
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
  });

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
