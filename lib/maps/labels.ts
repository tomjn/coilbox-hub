import type { Footprint } from "@/lib/assets/placeholder";

/**
 * What a map's row says, in the words a page prints (#190).
 *
 * `public.map` stores a size in elmos, a set of start positions and a wind range
 * that is sometimes half missing. Nobody says any of that out loud. They say "a
 * 12 by 20" and "an 8 player map", and those are the sentences a page has to
 * carry.
 *
 * Here rather than in the page, because the map listing (#191) prints the same
 * sentences on every card. Two copies would drift the first time one of them
 * decided a half square was worth showing, and the way it would show is one map
 * called 12 x 20 in a list and 12.5 x 20 on its own page.
 */

/**
 * The engine's map square, in elmos.
 *
 * Map dimensions are whole squares of 512 elmos and every map size a player says
 * out loud is a count of them, which is the same constant and the same reasoning
 * `20260818120000_map_listing.sql` states for its size bands. This one is the
 * engine's and no game changes it.
 */
const ELMOS_PER_SQUARE = 512;

/** A fraction of a square is kept to one place rather than rounded away. Real
 *  maps are whole squares, so this only ever shows on a row that is not, and
 *  rounding it off would print a whole number the map does not have. */
function squares(elmos: number): number {
  return Math.round((elmos / ELMOS_PER_SQUARE) * 10) / 10;
}

/**
 * The size as `12 x 20`.
 *
 * No unit, because there is none to name: "12 x 20" is exactly how BAR, the
 * lobby and every player says a map size, and appending a noun to it would
 * invent one. That is the same reading `lib/assets/placeholder.ts` gives for the
 * caption under a map it holds no picture of.
 */
export function mapSizeLabel(widthElmos: number, heightElmos: number): string {
  return `${squares(widthElmos)} x ${squares(heightElmos)}`;
}

/**
 * The size as a footprint for `components/AssetPlaceholder.tsx`.
 *
 * In squares rather than elmos, which is the unit `lib/assets/placeholder.ts`
 * says a map's footprint is in. The numbers only decide the shape of the drawing
 * so either unit would draw the same box, and the caption under it is the
 * placeholder's own and would read "6144 by 10240" in elmos.
 *
 * With the label rather than beside it, because both are the same division by
 * the same square. Splitting them would put that constant in two files.
 */
export function mapSquares(widthElmos: number, heightElmos: number): Footprint {
  return { width: squares(widthElmos), height: squares(heightElmos) };
}

/**
 * How many people the map is for, from the number of start positions on it, or
 * null when it has none stored.
 *
 * Null rather than "0 players". A map with no start positions is one the hub
 * holds an incomplete extraction of rather than a map nobody can play, and the
 * page leaves the fact out instead of printing something untrue. Start positions
 * are the only count there is: `20260818100000_map_catalog.sql` records one
 * point per team spawn, and a lobby's team layout is the lobby's to choose.
 */
export function playerCountLabel(startPositions: number): string | null {
  if (startPositions < 1) return null;
  return startPositions === 1 ? "1 player" : `${startPositions} players`;
}

/**
 * The wind the map declares, or null when it declares no range.
 *
 * Both ends or neither. mapinfo leaves wind out and the engine falls back to its
 * own defaults, so half a range is a map that said nothing rather than a map
 * with one windless end, and printing `up to 25` would put a number on the page
 * the archive never gave. That is the reading `20260818120000_map_listing.sql`
 * already takes: its windy tag is the midpoint of the range, and a midpoint of a
 * half range is null.
 */
export function windLabel(minWind: number | null, maxWind: number | null): string | null {
  if (minWind === null || maxWind === null) return null;
  return minWind === maxWind ? `${minWind}` : `${minWind} to ${maxWind}`;
}

/**
 * What to call the map: the name in its own mapinfo where it has one, and the
 * canonical name otherwise.
 *
 * The canonical name is identity and is never parsed, version string and all,
 * which is the rule `20260818100000_map_catalog.sql` sets out. The display name
 * is what the archive would rather be called and plenty of archives fill in
 * neither, so a page that only ever printed one of the two would either show a
 * heading with a version number in it or no heading at all.
 */
export function mapTitle(displayName: string | null, mapName: string): string {
  return displayName?.trim() || mapName;
}
