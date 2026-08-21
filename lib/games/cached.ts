import { cacheLife, cacheTag } from "next/cache";
import { TAGS } from "@/lib/cache/tags";
import { createAnonClient } from "@/lib/supabase/anon";
import { fetchGames, type GameSummary } from "./query";

/**
 * The games listing's read, held between requests (#225).
 *
 * `lib/maps/cached.ts` sets out why the catalog's reads live behind a cache and
 * read through an anonymous client. This page has the same shape with less
 * inside it: no pictures to carry across the boundary yet, because a game card
 * is typographic until a game can have a logo of its own (#229), which is a
 * column that does not exist yet rather than one nobody filled in.
 */

const LISTING_LIFE = "hours";

/** Every game, biggest first. */
export async function gamesListing(): Promise<{
  games: GameSummary[];
  error: string | null;
}> {
  "use cache";
  cacheLife(LISTING_LIFE);
  cacheTag(TAGS.games);

  return fetchGames(createAnonClient());
}
