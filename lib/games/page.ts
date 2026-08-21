import type { SupabaseClient } from "@supabase/supabase-js";
import { parseGameLinks, type GameLink } from "./catalog";

/**
 * Everything one game's page shows, in one place a test can reach (#226).
 *
 * The same reasoning `lib/maps/page.ts` gives for sitting between a page and
 * the catalog: what a page did wrong is hard to see through a render and easy
 * to see through a returned object. Unlike the map page, nothing here needs the
 * secret key - there is no licence gate in front of a game - so the whole read
 * runs through an anonymous client and row level security.
 */

export interface GamePageFaction {
  key: string;
  name: string;
  logo_path: string | null;
}

export interface GamePage {
  shortname: string;
  display_name: string | null;
  description: string | null;
  links: GameLink[];
  faction_count: number;
  unit_count: number;
  /** Alphabetical, so the strip reads the same way every time it is built. */
  factions: GamePageFaction[];
  /** The release most recently reported, which is how fresh the facts are.
   *  Null until a client has said. */
  release: string | null;
  /** Who holds the pen, if anybody. The page shows their game's words where
   *  they exist and offers the request button where they do not. */
  owner_user_id: string | null;
  /** The owner's images, where they have uploaded any (#229). */
  logo_path: string | null;
  banner_path: string | null;
}

/** The row as the query hands it back, before the page shapes it. */
interface GameRow {
  shortname: string;
  display_name: string | null;
  description: string | null;
  links: unknown;
  owner_user_id: string | null;
  logo_path: string | null;
  banner_path: string | null;
  game_faction: { key: string; name: string; logo_path: string | null }[];
  game_version: { version: string }[];
}

export async function loadGamePage(
  supabase: SupabaseClient,
  shortname: string,
): Promise<GamePage | null> {
  // One query for the row and everything hanging off it, one for the two
  // aggregates. The counts live in public.game_browse rather than on public.game
  // because they are queries and not stored values, which
  // 20260821120000_game_browse.sql argues at length; the embeds ride the foreign
  // keys the catalog tables already declare.
  const [row, counts] = await Promise.all([
    supabase
      .from("game")
      .select(
        "shortname,display_name,description,links,owner_user_id,logo_path,banner_path," +
          "game_faction(key,name,logo_path)," +
          "game_version(version,last_seen_at)",
      )
      .eq("shortname", shortname)
      .order("name", { referencedTable: "game_faction", ascending: true })
      .order("last_seen_at", { referencedTable: "game_version", ascending: false })
      .limit(1, { referencedTable: "game_version" })
      .maybeSingle(),
    supabase
      .from("game_browse")
      .select("faction_count,unit_count")
      .eq("shortname", shortname)
      .maybeSingle(),
  ]);

  const held = row.data as GameRow | null;
  if (row.error || !held || counts.error || !counts.data) return null;

  return {
    shortname: held.shortname,
    display_name: held.display_name,
    description: held.description,
    links: parseGameLinks(held.links),
    faction_count: counts.data.faction_count,
    unit_count: counts.data.unit_count,
    factions: held.game_faction ?? [],
    release: held.game_version?.[0]?.version ?? null,
    owner_user_id: held.owner_user_id,
    logo_path: held.logo_path,
    banner_path: held.banner_path,
  };
}
