import { NextResponse } from "next/server";
import { corsPreflight, withCors } from "@/lib/api/cors";
import { apiError } from "@/lib/api/response";
import { createClient } from "@/lib/supabase/server";

/**
 * Coilbox pings this once an import that started from a hub link has been
 * applied (coilbox/coilbox#1361). It is fire and forget: the import already
 * succeeded before this request is sent, so there is nothing for the caller
 * to read back, and that is why success below is a bare 204 rather than the
 * `format`/`version` envelope every other `/api/v1` response carries - there
 * is no body to version.
 *
 * No authentication, the same as `/i/<id>`: coilbox has no session to send,
 * and nothing about counting an import needs one.
 */
export const OPTIONS = corsPreflight;

export async function POST(
  _request: Request,
  { params }: { params: Promise<{ id: string }> },
) {
  const { id } = await params;

  const supabase = await createClient();
  const { data, error } = await supabase.rpc("record_item_imported", {
    target_id: id,
  });

  // record_item_imported updates nothing for an id that never existed and
  // nothing for a withdrawn one, the same as every other per-item route:
  // both are indistinguishable from "not found" without it knowing which.
  if (error || !data) {
    return apiError("No such item.", 404);
  }

  return withCors(new NextResponse(null, { status: 204 }));
}
