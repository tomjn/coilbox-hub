import type { SupabaseClient } from "@supabase/supabase-js";
import { gameTitle } from "./labels";

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
  /** The logo's hash, and the store holding a copy still waiting for
   *  promotion (#345). `gameArtUrl` in `./art` turns the three into a URL. */
  logo_hash: string | null;
  logo_staged_tier: string | null;
  /** When a moderator put this game above the divider, or null. The listing
   *  reads it as a flag. The time is kept because who and when are part of the
   *  fact, the same way `hidden_at` is. */
  featured_at: string | null;
  /** Where a lobby fetches the game from, or null when nobody has said. Both
   *  columns are set or neither is, which the table's own check enforces. */
  download_kind: string | null;
  download_value: string | null;
  /** The 16:9 picture the card draws above the name, resolved by the same
   *  three columns the logo uses. */
  card_path: string | null;
  card_hash: string | null;
  card_staged_tier: string | null;
  faction_count: number;
  unit_count: number;
  /** Live community content filed under this game's shortname (#244). */
  item_count: number;
}

/** Everything `public.game_browse` publishes, which is the whole of what a card
 * shows. */
/* One string literal rather than a concatenation, even at this length: the
   supabase-js select is typed off the literal, and a `+` widens it to `string`,
   at which point the rows come back as an error type. */
export const GAME_SUMMARY_COLUMNS =
  "shortname,display_name,description,logo_path,logo_hash,logo_staged_tier,featured_at,download_kind,download_value,card_path,card_hash,card_staged_tier,faction_count,unit_count,item_count";

/**
 * Featured first, then alphabetical.
 *
 * This used to be biggest first, on the theory that a visitor arriving cold
 * wants the game with the most units to read about. Size turned out to be a
 * poor stand-in for that, and it left the order at the mercy of whichever
 * extraction ran last. A moderator now says what goes first, and everything
 * under that is where a person would look for it.
 *
 * The comparison is on the title a reader sees rather than the shortname,
 * because the title is the string they are scanning. `localeCompare` so an
 * accented name lands where a reader expects rather than after Z. Shortname is
 * the final tiebreak, and it is unique, so the order does not shuffle between
 * requests the way a timestamp tie would.
 */
export function compareGames(left: GameSummary, right: GameSummary): number {
  const leftFeatured = left.featured_at !== null;
  const rightFeatured = right.featured_at !== null;
  if (leftFeatured !== rightFeatured) return leftFeatured ? -1 : 1;

  const byTitle = gameTitle(left).localeCompare(gameTitle(right));
  if (byTitle !== 0) return byTitle;

  return left.shortname.localeCompare(right.shortname);
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
