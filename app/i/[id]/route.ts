import { NextResponse } from "next/server";
import { corsPreflight, withCors } from "@/lib/api/cors";
import { createClient } from "@/lib/supabase/server";

/**
 * The raw container, at a URL that does not move.
 *
 * This is the whole import side of the loop. Shipped coilbox already fetches an
 * https URL from the Rust side, enforces https, caps the response, identifies the
 * container and asks the user to confirm, so an Import button is nothing more
 * than `coilbox://import?url=https://<host>/i/<id>`.
 *
 * These URLs get pasted into Discord and will outlive whatever the site looks
 * like, so this route is deliberately separate from the item page and should not
 * follow it around.
 */
export const OPTIONS = corsPreflight;

export async function GET(
  _request: Request,
  { params }: { params: Promise<{ id: string }> },
) {
  const { id } = await params;

  const supabase = await createClient();
  const { data, error } = await supabase
    .from("item")
    .select("container")
    .eq("id", id)
    .maybeSingle();

  // A withdrawn item is invisible to the read policy, so it arrives here as
  // "not found" without this route having to know about moderation at all.
  if (error || !data) {
    return withCors(NextResponse.json({ error: "No such item." }, { status: 404 }));
  }

  return withCors(
    NextResponse.json(data.container, {
      headers: {
        // Short, because withdrawing something has to actually take it away.
        // Long enough to absorb a link being shared into a busy channel.
        "Cache-Control": "public, max-age=60, s-maxage=60",
      },
    }),
  );
}
