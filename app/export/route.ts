import { NextResponse } from "next/server";
import { createClient } from "@/lib/supabase/server";

/**
 * The whole public gallery as one file.
 *
 * This is the exit. It is the backup, it is what coilbox could fall back on when
 * the service is unreachable, and it means that if this is ever abandoned the
 * community can pick the data up and rehost it rather than losing everything
 * people made.
 *
 * It exists now rather than later on purpose: an export added once the service
 * matters is an export that has never been tested.
 *
 * Requesting it also counts as traffic, which is what stops a free tier project
 * pausing after a quiet week.
 */
export const dynamic = "force-dynamic";

export async function GET() {
  const supabase = await createClient();

  // Withdrawn items are invisible to the read policy, so the export carries what
  // the public can see and nothing else, with no filtering here to get wrong.
  const { data, error } = await supabase
    .from("item")
    .select(
      "id,kind,mode,title,description,game_name,map_name,tags,container,author_name,created_at,updated_at",
    )
    .order("created_at", { ascending: false })
    .limit(5000);

  if (error) {
    return NextResponse.json({ error: error.message }, { status: 503 });
  }

  return NextResponse.json(
    {
      format: "coilbox-hub-export",
      version: 1,
      count: data?.length ?? 0,
      items: data ?? [],
    },
    {
      headers: {
        "Cache-Control": "public, max-age=300, s-maxage=300",
        "Content-Disposition": 'inline; filename="coilbox-hub.json"',
      },
    },
  );
}
