import { corsPreflight } from "@/lib/api/cors";
import { buildGameListBody } from "@/lib/api/gameList";
import { apiError, apiJson } from "@/lib/api/response";
import { fetchGames } from "@/lib/games/query";
import { createAnonClient } from "@/lib/supabase/anon";

/**
 * Every game the hub holds, as the listing page holds it.
 *
 * The same `fetchGames` the page calls, through the same anonymous client, so
 * the page and this route cannot come to disagree about what is published or in
 * what order. `public.game_browse` is security invoker over tables carrying
 * read-all policies, so a hidden game is invisible here exactly as it is on the
 * page, without this route knowing hiding exists.
 *
 * ## A failed read is a 503, not an empty list
 *
 * An empty list is a claim: the hub holds no games. A caller that acted on a
 * claim the hub could not actually make would stop asking about games the
 * catalog holds. `/api/v1/maps/lookup` records the same reasoning for the same
 * choice.
 */
export const OPTIONS = corsPreflight;

export async function GET() {
  const { games, error } = await fetchGames(createAnonClient());
  if (error) {
    console.error("GET /api/v1/games: the catalog could not be read", error);
    return apiError("The catalog could not be read just now.", 503);
  }
  return apiJson(buildGameListBody(games));
}
