import type { NextRequest } from "next/server";
import { corsPreflight } from "@/lib/api/cors";
import { apiError, apiJson } from "@/lib/api/response";
import { buildItemBody, buildItemsListBody, parseApiFilters } from "@/lib/api/items";
import { parsePublishBody, statusForPublishFailure } from "@/lib/api/publish";
import {
  applyFilters,
  ITEM_SUMMARY_COLUMNS,
  type ItemSummary,
  PAGE_SIZE,
} from "@/lib/gallery/query";
import { publishItem } from "@/lib/gallery/publish";
import { requestOrigin } from "@/lib/gallery/origin";
import { authenticateBearer } from "@/lib/supabase/bearer";
import { createClient } from "@/lib/supabase/server";

/**
 * List and search, as JSON, for the desktop client. The same filters and the
 * same 24-per-page the website's `/gallery` uses, built through the same
 * `applyFilters` so the two never drift apart.
 *
 * An unrecognised query parameter, or a `kind` the gallery does not carry, is
 * a 400 rather than a filter quietly not applying. See `parseApiFilters` for
 * why: a client that thinks it filtered and gets everything back is the
 * failure this route exists to avoid.
 */
export const dynamic = "force-dynamic";

export const OPTIONS = corsPreflight;

export async function GET(request: NextRequest) {
  const parsed = parseApiFilters(request.nextUrl.searchParams);
  if (!parsed.ok) {
    return apiError(parsed.error, 400);
  }
  const { filters } = parsed;

  const supabase = await createClient();
  const query = applyFilters(
    supabase
      .from("item")
      .select(ITEM_SUMMARY_COLUMNS, { count: "exact" })
      .order("created_at", { ascending: false })
      .range((filters.page - 1) * PAGE_SIZE, filters.page * PAGE_SIZE - 1),
    filters,
  );

  const { data, count, error } = await query;
  if (error) {
    return apiError("The gallery could not be read just now.", 503);
  }

  const items = (data ?? []) as unknown as ItemSummary[];
  return apiJson(buildItemsListBody(items, filters.page, count ?? 0));
}

/**
 * Publish, over HTTP, for the desktop client. A thin wrapper over
 * `publishItem` - the same validation the form uses, from `accept()` through
 * to the insert - so this and `app/publish/actions.ts` are one code path
 * rather than two that can drift (issue 25).
 *
 * Authenticated by bearer token rather than the session cookie: the wildcard
 * CORS origin above cannot carry credentials. `authenticateBearer` hands back
 * a client whose row level security runs as that token's user, so nothing in
 * the request body about who is publishing is ever trusted.
 */
export async function POST(request: NextRequest) {
  const auth = await authenticateBearer(request);
  if (!auth.ok) {
    return apiError(
      auth.reason === "missing"
        ? 'Send an access token as "Authorization: Bearer <token>".'
        : "That access token is not valid. Sign in again and use a fresh one.",
      401,
    );
  }

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return apiError("The request body must be JSON.", 400);
  }

  const parsed = parsePublishBody(body);
  if (!parsed.ok) {
    return apiError(parsed.error, 400);
  }

  const outcome = await publishItem(auth.supabase, auth.user.id, parsed.fields);
  if (!outcome.ok) {
    return apiError(outcome.reason, statusForPublishFailure(outcome.status));
  }

  const origin = await requestOrigin();
  return apiJson(buildItemBody(outcome.item, `${origin}/i/${outcome.item.id}`), 201);
}
