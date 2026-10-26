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

  // Nothing to refresh for a signed out visitor, which is most visits and every
  // crawler.
  if (!hasAuthCookie(request.cookies.getAll())) return NextResponse.next();

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

  // Reading the session is what performs the refresh. Do not remove it.
  // `getClaims` rather than `getUser`: both go through the cookie adapter above
  // and rotate an expired session, but `getClaims` verifies the token against
  // the project's signing keys, which it caches, and only asks the auth server
  // when it cannot. `getUser` asked on every request.
  await supabase.auth.getClaims();

  return response;
}

/** Whether the cookies hold a Supabase session, as `sb-<ref>-auth-token` or
 *  split into `.0`, `.1` chunks when it is too long for one cookie. */
export function hasAuthCookie(cookies: { name: string }[]): boolean {
  return cookies.some(({ name }) => /^sb-.+-auth-token(\.\d+)?$/.test(name));
}

export const config = {
  matcher: [
    // Pages only. Vercel runs the proxy before its cache, so every request it
    // matches costs a function call, even one the CDN could have answered.
    // Left out: static assets, Vercel's own endpoints, the generated OpenGraph
    // images, and the routes that never read a session cookie. The API and
    // `/i/` take bearer tokens or nothing, `/assets/` serves pictures, and the
    // feed, sitemap, robots and export are the same for everyone.
    "/((?!_next/static|_next/image|_vercel|favicon.ico|.*\\.(?:svg|png|jpg|jpeg|gif|webp)$|opengraph-image|api/|i/|assets/|export$|feed\\.xml|sitemap\\.xml|robots\\.txt).*)",
  ],
};
