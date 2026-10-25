import type { SupabaseClient } from "@supabase/supabase-js";
import { gameTitle } from "./labels";

/**
 * The two reads that tie a mutator to its base game on the game page (#547).
 *
 * `public.game.base_game` is a shortname with no foreign key, because the base
 * game may not be on the hub. So the link back is a lookup that can come up
 * empty, and the page names the base game without a link when it does.
 *
 * Both reads go through `public.game_browse`, which is security invoker over
 * tables with the hiding rules on them. A hidden game is therefore neither
 * listed as a mutator nor linked as a base game, with nothing here knowing
 * hiding exists.
 */

export interface RelatedGame {
  shortname: string;
  /** What to print: the display name where one is held, the shortname otherwise. */
  title: string;
}

interface Row {
  shortname: string;
  display_name: string | null;
}

function related(row: Row): RelatedGame {
  return { shortname: row.shortname, title: gameTitle(row) };
}

/** The base game as the hub holds it, or null when it is not there or cannot be
 *  read. */
export async function loadBaseGame(
  supabase: SupabaseClient,
  baseGame: string,
): Promise<RelatedGame | null> {
  const { data, error } = await supabase
    .from("game_browse")
    .select("shortname,display_name")
    .eq("shortname", baseGame)
    .maybeSingle();
  if (error || !data) return null;
  return related(data as Row);
}

/** Every game built on this one, alphabetical by the title a reader sees, with
 *  the shortname as the tiebreak. Empty when there are none or the read fails. */
export async function loadMutators(
  supabase: SupabaseClient,
  shortname: string,
): Promise<RelatedGame[]> {
  const { data, error } = await supabase
    .from("game_browse")
    .select("shortname,display_name")
    .eq("base_game", shortname);
  if (error || !data) return [];
  return (data as Row[])
    .map(related)
    .sort((left, right) => left.title.localeCompare(right.title) || left.shortname.localeCompare(right.shortname));
}
