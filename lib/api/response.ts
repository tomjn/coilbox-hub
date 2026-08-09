import { NextResponse } from "next/server";
import { withCors } from "@/lib/api/cors";

/**
 * The one seam every `/api/v1` route shares to build a response. This is
 * where CORS headers (issue 24) get added, via `withCors`, so both routes
 * get them from one place rather than route by route.
 *
 * Only success responses carry the cache header, matching `/i/<id>`: an error
 * is not something a CDN should hold onto.
 */
const SUCCESS_HEADERS = {
  // Short, for the same reason `/i/<id>` is: withdrawing an item has to
  // actually take it away within a reasonable time of being reported.
  "Cache-Control": "public, max-age=60, s-maxage=60",
};

export function apiJson<T>(body: T, status = 200): NextResponse {
  return withCors(NextResponse.json(body, { status, headers: SUCCESS_HEADERS }));
}

export function apiError(message: string, status: number): NextResponse {
  return withCors(NextResponse.json({ error: message }, { status }));
}
