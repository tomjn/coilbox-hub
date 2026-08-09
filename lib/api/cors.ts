import { NextResponse } from "next/server";

/**
 * `/api/v1/items`, `/api/v1/items/<id>`, `/i/<id>` and `/export` are the whole
 * public read surface: nobody's session cookie grants extra access to them,
 * and nothing about the response changes for who is asking. That is what
 * makes `Access-Control-Allow-Origin: *` safe here specifically, rather than
 * a general policy - there is no cookie authority to leak by widening who can
 * read them.
 *
 * The wildcard is also the only origin value that actually covers a desktop
 * client: a Tauri webview's origin is `tauri://localhost` on macOS and
 * `http://tauri.localhost` on Windows, so an allow-list would have to
 * enumerate platforms today and would still miss whatever the next one uses.
 *
 * `*` cannot be combined with credentialed requests (cookies, `Authorization`
 * with `credentials: include`), which is fine: these routes never read
 * cookies, and issue 25's bearer token is sent as a plain header rather than
 * relying on browser credential handling.
 *
 * `POST` is issue 25's `POST /api/v1/items`: a browser will not send a
 * method a preflight did not advertise here, however correct the route
 * itself is, so this list has to grow with the API rather than the route
 * alone.
 */
export const CORS_HEADERS = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
  // `Authorization` is issue 25's bearer token. `Content-Type` is included
  // because sending it as `application/json` on a request also triggers a
  // preflight, and issue 25's POST always does.
  "Access-Control-Allow-Headers": "Authorization, Content-Type",
};

/** Adds the CORS headers to a response built elsewhere, so a route that
 * already has a `NextResponse` (built by hand rather than through `apiJson`)
 * can still share this one definition instead of repeating it. */
export function withCors(response: NextResponse): NextResponse {
  for (const [name, value] of Object.entries(CORS_HEADERS)) {
    response.headers.set(name, value);
  }
  return response;
}

/**
 * Answers a CORS preflight. A plain `GET` never preflights, but the moment a
 * caller sends `Authorization` or a `Content-Type` outside the simple-request
 * allow list, the browser sends `OPTIONS` first and expects these headers
 * back before it will make the real request. Issue 25 is what starts sending
 * `Authorization`, so this has to exist now rather than be discovered broken
 * once that lands.
 */
export function corsPreflight(): NextResponse {
  return withCors(new NextResponse(null, { status: 204 }));
}
