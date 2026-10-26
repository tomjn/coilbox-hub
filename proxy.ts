import { createServerClient } from "@supabase/ssr";
import { NextResponse, type NextRequest } from "next/server";
import { apiError } from "@/lib/api/response";
import { hasAuthCookie } from "@/lib/auth/navCookie";
import { getSupabaseConfig, SUPABASE_CONFIG_ERROR } from "@/lib/supabase/config";

/**
 * Refreshes the session on every request and writes the rotated cookies onto the
 * response. Without this a signed in visitor looks signed out to the server after
 * their access token expires, and publishing fails for no visible reason.
 *
 * It also catches a deployment missing its Supabase configuration on the pages
 * it covers (see `config` below). Without this check, `createServerClient`
 * threw here with an `undefined` URL and the visitor got Next's generic HTML
 * 500 page (issue 54). A browser gets plain text explaining the deployment is
 * broken instead, rather than a page that quietly limps along.
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


export const config = {
  // Only the pages that read the session on the server, and the routes that
  // sign people in and out. Vercel runs the proxy before its cache, so every
  // request it matches costs a function call, even one the CDN could have
  // answered. Every other page is the same for everybody and draws who is signed
  // in from cookies in the browser, so it has no session to refresh. A server
  // action posted from one of those pages refreshes the session itself, since
  // an action can write cookies.
  matcher: [
    "/account/:path*",
    "/publish/:path*",
    "/moderation/:path*",
    "/ops/:path*",
    "/sign-in",
    "/item/:id/edit",
    "/item/:id/withdrawn",
    "/games/:shortname/edit",
    "/auth/:path*",
    "/dev/:path*",
  ],
};
