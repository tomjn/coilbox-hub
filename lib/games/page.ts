import type { SupabaseClient } from "@supabase/supabase-js";
import { type ConquestFaction, parseConquestFactions, parseGameLinks, type GameLink } from "./catalog";
import { type GameDownload, readDownloads } from "./download";
import { isRandomFaction } from "./factions";

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
  /** The owner or moderator authored conquest faction list (#393), holding it
   *  rather than using it - drawing a shared galaxy from it is #397. */
  conquest_factions: ConquestFaction[];
  faction_count: number;
  unit_count: number;
  /** Live community content filed under this shortname (#244). */
  item_count: number;
  /** Alphabetical, so the strip reads the same way every time it is built.
   *  Random is left out, because it is a lobby choice and not a side. */
  factions: GamePageFaction[];
  /** The release most recently reported, which is how fresh the facts are.
   *  Null until a client has said. */
  release: string | null;
  /** Who holds the pen, if anybody. The page shows their game's words where
   *  they exist and offers the request button where they do not. */
  owner_user_id: string | null;
  /** Set when a moderator or the owner has taken the game off the site (#242).
   *  Only ever non-null on reads through a client that may see it. */
  hidden_at: string | null;
  /** The owner's images, where they have uploaded any (#229), with the hash of
   *  each and the store holding a copy still waiting for promotion (#345).
   *  `gameArtUrl` in `./art` turns the three into a URL. */
  logo_path: string | null;
  logo_hash: string | null;
  logo_staged_tier: string | null;
  banner_path: string | null;
  banner_hash: string | null;
  banner_staged_tier: string | null;
  card_path: string | null;
  card_hash: string | null;
  card_staged_tier: string | null;
  /** Where coilbox fetches the game, best source first (#396). Empty when the
   *  game names nowhere. */
  downloads: GameDownload[];
}

/** The row as the query hands it back, before the page shapes it. */
interface GameRow {
  shortname: string;
  display_name: string | null;
  description: string | null;
  links: unknown;
  conquest_factions: unknown;
  owner_user_id: string | null;
  hidden_at: string | null;
  logo_path: string | null;
  logo_hash: string | null;
  logo_staged_tier: string | null;
  banner_path: string | null;
  banner_hash: string | null;
  banner_staged_tier: string | null;
  card_path: string | null;
  card_hash: string | null;
  card_staged_tier: string | null;
  game_download_source: unknown;
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
        "shortname,display_name,description,links,conquest_factions,owner_user_id,hidden_at," +
          "logo_path,logo_hash,logo_staged_tier,banner_path,banner_hash,banner_staged_tier," +
          "card_path,card_hash,card_staged_tier," +
          "game_download_source(kind,value,asset,filename,sort_order)," +
          "game_faction(key,name,logo_path)," +
          "game_version(version,last_seen_at)",
      )
      .eq("shortname", shortname)
      .order("sort_order", { referencedTable: "game_download_source", ascending: true })
      .order("id", { referencedTable: "game_download_source", ascending: true })
      .order("name", { referencedTable: "game_faction", ascending: true })
      .order("last_seen_at", { referencedTable: "game_version", ascending: false })
      .limit(1, { referencedTable: "game_version" })
      .maybeSingle(),
    supabase
      .from("game_browse")
      .select("faction_count,unit_count,item_count")
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
    conquest_factions: parseConquestFactions(held.conquest_factions),
    faction_count: counts.data.faction_count,
    unit_count: counts.data.unit_count,
    item_count: counts.data.item_count,
    factions: (held.game_faction ?? []).filter((faction) => !isRandomFaction(faction)),
    release: held.game_version?.[0]?.version ?? null,
    owner_user_id: held.owner_user_id,
    hidden_at: held.hidden_at,
    logo_path: held.logo_path,
    logo_hash: held.logo_hash,
    logo_staged_tier: held.logo_staged_tier,
    banner_path: held.banner_path,
    banner_hash: held.banner_hash,
    banner_staged_tier: held.banner_staged_tier,
    card_path: held.card_path,
    card_hash: held.card_hash,
    card_staged_tier: held.card_staged_tier,
    downloads: readDownloads(held.game_download_source),
  };
}
