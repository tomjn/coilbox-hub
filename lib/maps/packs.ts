import type { SupabaseClient } from "@supabase/supabase-js";
import { fetchAllPages } from "@/lib/gallery/query";

/**
 * Map packs: named bundles of maps a moderator puts together for coilbox's Map
 * packs menu (tomjn/coilbox#3206).
 *
 * `20260927120000_map_pack.sql` has the schema and why an entry names a map
 * rather than pointing at its row. This file is the read that
 * `GET /api/v1/map-packs` and the moderation pages share, plus the words the
 * moderation forms answer with.
 */

export interface MapPackEntry {
  mapName: string;
  /** Null when the catalog no longer holds the map. */
  slug: string | null;
  displayName: string | null;
  archiveFilename: string | null;
}

export interface MapPack {
  id: string;
  title: string;
  blurb: string | null;
  featuredAt: string | null;
  /** In the order the moderator added them. */
  maps: MapPackEntry[];
}

interface PackRow {
  id: string;
  title: string;
  blurb: string | null;
  featured_at: string | null;
}

interface EntryRow {
  pack_id: string;
  map_name: string;
  slug: string | null;
  display_name: string | null;
  archive_filename: string | null;
}

/** `max_rows` in `supabase/config.toml`. `fetchAllPages` copes with a lower cap
 *  on the hosted project, so this only sets how many requests a large pack takes. */
const ENTRY_PAGE_SIZE = 1000;

/** Featured packs first, the most recently featured at the top, then the rest
 *  by title. The same order the moderation page lists them in. */
function comparePacks(a: PackRow, b: PackRow): number {
  if (a.featured_at && b.featured_at) return b.featured_at.localeCompare(a.featured_at);
  if (a.featured_at) return -1;
  if (b.featured_at) return 1;
  return a.title.localeCompare(b.title);
}

/**
 * Every pack with its maps, or an error.
 *
 * Entries are paged rather than read in one request. A pack of every map on a
 * mirror can pass the Data API's per request row cap on its own, and a read cut
 * short by the cap looks exactly like a smaller pack.
 */
export async function fetchMapPacks(
  supabase: SupabaseClient,
  onlyId?: string,
): Promise<{ packs: MapPack[]; error: string | null }> {
  let packQuery = supabase.from("map_pack").select("id, title, blurb, featured_at");
  if (onlyId) packQuery = packQuery.eq("id", onlyId);
  const { data: packRows, error: packError } = await packQuery;
  if (packError) return { packs: [], error: packError.message };

  const { data: entryRows, error: entryError } = await fetchAllPages<EntryRow>(
    async (from, to) => {
      let entryQuery = supabase
        .from("map_pack_entry")
        .select("pack_id, map_name, slug, display_name, archive_filename", { count: "exact" });
      if (onlyId) entryQuery = entryQuery.eq("pack_id", onlyId);
      return await entryQuery.order("pack_id").order("position").range(from, to);
    },
    ENTRY_PAGE_SIZE,
  );
  if (entryError) return { packs: [], error: entryError };

  const byPack = new Map<string, MapPackEntry[]>();
  for (const row of entryRows) {
    const entries = byPack.get(row.pack_id) ?? [];
    entries.push({
      mapName: row.map_name,
      slug: row.slug,
      displayName: row.display_name,
      archiveFilename: row.archive_filename,
    });
    byPack.set(row.pack_id, entries);
  }

  const packs = ((packRows ?? []) as PackRow[]).sort(comparePacks).map((row) => ({
    id: row.id,
    title: row.title,
    blurb: row.blurb,
    featuredAt: row.featured_at,
    maps: byPack.get(row.id) ?? [],
  }));

  return { packs, error: null };
}

/** One pack with its maps, or null when there is no such pack or it could not
 *  be read. For the moderation page, where either way there is nothing to draw. */
export async function fetchMapPack(supabase: SupabaseClient, id: string): Promise<MapPack | null> {
  const { packs, error } = await fetchMapPacks(supabase, id);
  if (error) return null;
  return packs[0] ?? null;
}

/**
 * The lines of a pasted list, trimmed, with blank lines dropped.
 *
 * One per line, because that is how a mirror's file list and a column copied out
 * of a spreadsheet both arrive. Commas are not separators: map names carry them.
 */
export function parsePastedMapNames(text: string): string[] {
  return text
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line !== "");
}

/** The pack title and blurb limits, the same as the table's checks. */
export const MAP_PACK_TITLE_MAX = 128;
export const MAP_PACK_BLURB_MAX = 2000;

/**
 * Whether a title and blurb would pass the table's checks, so a form can say
 * what is wrong instead of reporting a failed save.
 */
export function readPackText(
  title: string,
  blurb: string,
): { title: string; blurb: string | null } | null {
  const cleanTitle = title.trim();
  const cleanBlurb = blurb.trim();
  if (cleanTitle === "" || cleanTitle.length > MAP_PACK_TITLE_MAX) return null;
  if (cleanBlurb.length > MAP_PACK_BLURB_MAX) return null;
  return { title: cleanTitle, blurb: cleanBlurb === "" ? null : cleanBlurb };
}

/** What the pack forms say. The same `{ ok, message }` shape as
 *  `MapFormState`, so `VisibilityToggleForm` draws them. */
export const MAP_PACK_MESSAGES = {
  featured: "Pack featured. Coilbox lists it in the Map packs menu.",
  unfeatured: "Pack no longer featured. Coilbox stops listing it.",
  saved: "Saved.",
  badText: `A pack needs a title of up to ${MAP_PACK_TITLE_MAX} characters, and a blurb of up to ${MAP_PACK_BLURB_MAX}.`,
  nothingPasted: "Paste at least one map name or archive filename, one per line.",
  removed: "Map taken out of the pack.",
  signedOut: "You are signed out. Sign in, then try again.",
  notAllowed: "Only a moderator can change a map pack.",
  notFound: "No pack with that id.",
  notSaved: "That could not be saved. Try again in a few minutes.",
  notSent: "The form did not reach the hub. Reload the page and try again.",
} as const;

/** What adding a pasted list says: how many lines matched, and which did not,
 *  so the moderator can fix those and paste them again. */
export function addedMapsMessage(results: { wanted: string; map_name: string | null }[]): string {
  const matched = results.filter((result) => result.map_name !== null).length;
  const missed = results.filter((result) => result.map_name === null).map((result) => result.wanted);
  const found = matched === 1 ? "1 line matched a map." : `${matched} lines matched a map.`;
  if (missed.length === 0) return `${found} Maps already in the pack stay where they were.`;
  return `${found} No map is called: ${missed.join(", ")}.`;
}
