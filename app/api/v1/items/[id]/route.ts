import { corsPreflight } from "@/lib/api/cors";
import { apiError, apiJson } from "@/lib/api/response";
import { buildItemBody } from "@/lib/api/items";
import { ITEM_SUMMARY_COLUMNS, type ItemSummary } from "@/lib/gallery/query";
import { requestOrigin } from "@/lib/gallery/origin";
import { createClient } from "@/lib/supabase/server";

/**
 * A single item, as JSON, for the desktop client. The same summary a listing
 * uses, plus `container_url` pointing at `/i/<id>` rather than the container
 * itself: that route already exists, is what coilbox's import link already
 * targets, and carries its own short cache lifetime for takedowns. Repeating
 * the container here would double the payload on every browse-then-view
 * round trip for no reader that needs it inline.
 *
 * A withdrawn item is invisible to the read policy, so it arrives here as
 * "not found" without this route knowing about moderation at all, the same
 * as `/i/<id>` and the item page.
 */
export const dynamic = "force-dynamic";

export const OPTIONS = corsPreflight;

export async function GET(
  _request: Request,
  { params }: { params: Promise<{ id: string }> },
) {
  const { id } = await params;

  const supabase = await createClient();
  const { data, error } = await supabase
    .from("item")
    .select(ITEM_SUMMARY_COLUMNS)
    .eq("id", id)
    .maybeSingle();

  if (error || !data) {
    return apiError("No such item.", 404);
  }

  const item = data as unknown as ItemSummary;
  const origin = await requestOrigin();
  return apiJson(buildItemBody(item, `${origin}/i/${item.id}`));
}
