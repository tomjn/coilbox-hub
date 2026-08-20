import { NextResponse } from "next/server";
import { corsPreflight, withCors } from "@/lib/api/cors";
import { fetchAllPages } from "@/lib/gallery/query";
import { createClient } from "@/lib/supabase/server";

const COLUMNS =
  "id,kind,mode,title,description,game_name,game_key,map_name,tags,container,author_name,created_at,updated_at";

interface ExportItem {
  id: string;
  kind: string;
  mode: string | null;
  title: string;
  description: string;
  game_name: string | null;
  game_key: string | null;
  map_name: string | null;
  tags: string[];
  container: string;
  author_name: string;
  created_at: string;
  updated_at: string;
}

/** Requested per page. `max_rows` can cap the actual response below this.
 * That cap differs locally versus on the cloud Data API. `fetchAllPages`
 * keeps requesting until the reported count is met, regardless of how small
 * the cap on any one page turns out to be. */
const EXPORT_PAGE_SIZE = 1000;

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
export const OPTIONS = corsPreflight;

export async function GET() {
  const supabase = await createClient();

  // Withdrawn items are invisible to the read policy, so the export carries what
  // the public can see and nothing else, with no filtering here to get wrong.
  const { data, error } = await fetchAllPages<ExportItem>(
    async (from, to) =>
      await supabase
        .from("item")
        .select(COLUMNS, { count: "exact" })
        .order("created_at", { ascending: false })
        .range(from, to),
    EXPORT_PAGE_SIZE,
  );

  if (error) {
    return withCors(NextResponse.json({ error }, { status: 503 }));
  }

  return withCors(
    NextResponse.json(
      {
        format: "coilbox-hub-export",
        version: 1,
        count: data.length,
        items: data,
      },
      {
        headers: {
          "Cache-Control": "public, max-age=300, s-maxage=300",
          "Content-Disposition": 'inline; filename="coilbox-hub.json"',
        },
      },
    ),
  );
}
