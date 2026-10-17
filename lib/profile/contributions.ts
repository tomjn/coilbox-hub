import type { SupabaseClient } from "@supabase/supabase-js";
import { fetchDisplayName } from "@/lib/author";
import { PAGE_SIZE } from "@/lib/gallery/query";
import { applyListingRule, fetchMapListingRule } from "@/lib/maps/lookup";

/**
 * What a contributor's public profile shows (issue #293).
 *
 * Every count is read as `anon`, with no function in between, so row level
 * security decides what is counted: approved pictures, live items and games a
 * moderator has not hidden. `supabase/tests/contributor_profile.test.sql` runs
 * the same reads and proves a queued picture, a withdrawn item and a hidden
 * game stay out. A signed in reader sees the same profile as a visitor, so
 * your own withdrawn items are not on it either.
 *
 * Maps are the one table whose rows are all readable, because the licence gate
 * that decides which maps have a page is readable by the secret key alone. So
 * the map query takes the same rule the catalog listing applies, and a map
 * whose page says not found is not counted.
 *
 * ## How many "recent" ones
 *
 * The gallery's page size. Nothing else on the hub names a handful, and a
 * profile section is the first page of the listing it stands for.
 */

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

/** The account id in a profile URL, lower cased, or null when it is not a uuid.
 *  Postgres rejects a malformed value for a uuid column, which would fail the
 *  page rather than answer not found. */
export function parseAccountId(raw: string): string | null {
  const id = raw.toLowerCase();
  return UUID.test(id) ? id : null;
}

export interface Contributions {
  name: string;
  /** Maps the account sent the facts for, newest first. Not the maps they made:
   *  `map.submitted_by` is whoever ran the client, not the person credited in
   *  the archive. */
  maps: { count: number; recent: { slug: string; label: string }[] };
  /** Approved pictures, unit and map variants together. No listing exists to
   *  link to, so only the count is shown. */
  pictures: { count: number };
  /** Games the account sent the facts for. */
  games: { count: number; recent: { shortname: string; label: string }[] };
  /** Live gallery items. */
  items: { count: number; recent: { id: string; title: string }[] };
}

interface Counted<Row> {
  count: number;
  rows: Row[];
}

async function recent<Row>(
  query: PromiseLike<{ data: unknown; count: number | null; error: { message: string } | null }>,
  what: string,
): Promise<Counted<Row>> {
  const { data, count, error } = await query;
  if (error) throw new Error(`Could not read ${what}: ${error.message}`);
  return { count: count ?? 0, rows: (data ?? []) as Row[] };
}

/**
 * Everything the profile of one account shows, or null when the account has no
 * profile row. `admin` is read for the licence gate and nothing else.
 */
export async function loadContributions(
  anon: SupabaseClient,
  admin: SupabaseClient,
  userId: string,
): Promise<Contributions | null> {
  const name = await fetchDisplayName(anon, userId);
  if (!name) return null;

  const gate = await fetchMapListingRule(admin);
  if (!gate.ok) throw new Error("The licence gate could not be read.");

  const [maps, pictures, games, items] = await Promise.all([
    recent<{ slug: string; map_name: string; display_name: string | null }>(
      applyListingRule(
        anon
          .from("map")
          .select("slug,map_name,display_name", { count: "exact" })
          .eq("submitted_by", userId),
        gate.rule,
      )
        .order("created_at", { ascending: false })
        .limit(PAGE_SIZE),
      "the maps",
    ),
    anon
      .from("asset")
      .select("id", { count: "exact", head: true })
      .eq("uploaded_by", userId)
      .then(({ count, error }) => {
        if (error) throw new Error(`Could not read the pictures: ${error.message}`);
        return count ?? 0;
      }),
    recent<{ shortname: string; display_name: string | null }>(
      anon
        .from("game")
        .select("shortname,display_name", { count: "exact" })
        .eq("submitted_by", userId)
        .order("created_at", { ascending: false })
        .limit(PAGE_SIZE),
      "the games",
    ),
    recent<{ id: string; title: string }>(
      anon
        .from("item")
        .select("id,title", { count: "exact" })
        .eq("author_id", userId)
        .order("created_at", { ascending: false })
        .limit(PAGE_SIZE),
      "the gallery items",
    ),
  ]);

  return {
    name,
    maps: {
      count: maps.count,
      recent: maps.rows.map((row) => ({ slug: row.slug, label: row.display_name ?? row.map_name })),
    },
    pictures: { count: pictures },
    games: {
      count: games.count,
      recent: games.rows.map((row) => ({
        shortname: row.shortname,
        label: row.display_name ?? row.shortname,
      })),
    },
    items: { count: items.count, recent: items.rows.map(({ id, title }) => ({ id, title })) },
  };
}
