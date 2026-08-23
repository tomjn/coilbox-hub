import type { SupabaseClient } from "@supabase/supabase-js";

/**
 * The games listing's read (#225).
 *
 * One query, no pagination, because a catalog of games is a shelf rather than a
 * warehouse: even every game the ecosystem ships is one comfortable page. The
 * filters and sorts the maps listing needs do not exist here yet for the same
 * reason - there is nothing to filter until there are enough games to need it.
 */

export interface GameSummary {
  shortname: string;
  display_name: string | null;
  description: string | null;
  /** Tier relative path to the game's logo, or null when the hub holds none
   *  (#239). A card without one keeps the typographic look. */
  logo_path: string | null;
  faction_count: number;
  unit_count: number;
  /** Live community content filed under this game's shortname (#244). */
  item_count: number;
}

/** Everything `public.game_browse` publishes, which is the whole of what a card
 * shows. */
export const GAME_SUMMARY_COLUMNS =
  "shortname,display_name,description,logo_path,faction_count,unit_count,item_count";

/**
 * Biggest first, then alphabetical.
 *
 * A visitor arriving cold wants the game people actually play, and that is the
 * one with the most units to read about. Two games with equal counts are told
 * apart by shortname, which is stable, so the order does not shuffle between
 * requests the way a timestamp tie would.
 */
function compareGames(left: GameSummary, right: GameSummary): number {
  if (left.unit_count !== right.unit_count) return right.unit_count - left.unit_count;
  return left.shortname < right.shortname ? -1 : left.shortname > right.shortname ? 1 : 0;
}

export async function fetchGames(
  supabase: SupabaseClient,
): Promise<{ games: GameSummary[]; error: string | null }> {
  const { data, error } = await supabase
    .from("game_browse")
    .select(GAME_SUMMARY_COLUMNS);

  if (error) return { games: [], error: error.message };
  return { games: (data ?? []).sort(compareGames), error: null };
}
