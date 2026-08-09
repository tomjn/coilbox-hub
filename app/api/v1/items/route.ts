import type { NextRequest } from "next/server";
import { corsPreflight } from "@/lib/api/cors";
import { apiError, apiJson } from "@/lib/api/response";
import { buildItemsListBody, parseApiFilters } from "@/lib/api/items";
import {
  applyFilters,
  ITEM_SUMMARY_COLUMNS,
  type ItemSummary,
  PAGE_SIZE,
} from "@/lib/gallery/query";
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
