import { cacheLife, cacheTag } from "next/cache";
import { TAGS } from "@/lib/cache/tags";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import { createAnonClient } from "@/lib/supabase/anon";
import { loadGamePage, type GamePage } from "./page";
import { fetchGames, type GameSummary } from "./query";
import {
  loadUnitComparison,
  loadUnitGrid,
  loadUnitPage,
  unitBuildpics,
  unitRender,
  type UnitComparison,
  type UnitGridFilters,
  type UnitPage,
} from "./units";

/**
 * The games catalog's reads, held between requests (#225, #226, #227).
 *
 * `lib/maps/cached.ts` sets out why the catalog's reads live behind a cache and
 * read through an anonymous client. The picture reads carry the assets tag
 * beside their own, for the same reason a map's do: an approved buildpic changes
 * a grid cell, and every loader that draws one has to hear about it.
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

/** One game's page. Null for a shortname nobody holds, which is the page's
 *  not-found. */
export async function gamePageCached(shortname: string): Promise<GamePage | null> {
  "use cache";
  cacheLife(LISTING_LIFE);
  cacheTag(TAGS.games);

  return loadGamePage(createAnonClient(), shortname);
}

/** One page of a game's units, with a buildpic per cell. */
export async function unitGridCached(
  shortname: string,
  filters: UnitGridFilters,
): Promise<{
  units: { unit: { unit_name: string; full_name: string | null; faction_key: string | null }; picture: ResolvedAsset }[];
  count: number;
  error: string | null;
}> {
  "use cache";
  cacheLife(LISTING_LIFE);
  cacheTag(TAGS.games, TAGS.assets);

  const supabase = createAnonClient();
  const grid = await loadUnitGrid(supabase, shortname, filters);
  const pictures = await unitBuildpics(supabase, shortname, grid.units);

  return {
    units: grid.units.map((unit) => ({
      unit,
      picture: pictures.get(unit.unit_name) ?? resolvePlaceholder(unit.unit_name),
    })),
    count: grid.count,
    error: grid.error,
  };
}

function resolvePlaceholder(unitName: string): ResolvedAsset {
  return { from: "placeholder", keyedOn: "unit", name: unitName, footprint: null };
}

/** One unit's page. Null when neither the game nor the unit is held. */
export async function unitPageCached(
  shortname: string,
  unitName: string,
  version?: string,
): Promise<{ page: UnitPage; render: ResolvedAsset } | null> {
  "use cache";
  cacheLife(LISTING_LIFE);
  cacheTag(TAGS.games, TAGS.assets);

  const supabase = createAnonClient();
  const page = await loadUnitPage(supabase, shortname, unitName, version);
  if (!page) return null;
  return { page, render: await unitRender(supabase, shortname, unitName) };
}

/** Two releases of one unit, side by side. */
export async function unitCompareCached(
  shortname: string,
  unitName: string,
  leftVersion: string,
  rightVersion: string,
): Promise<UnitComparison | null> {
  "use cache";
  cacheLife(LISTING_LIFE);
  cacheTag(TAGS.games);

  return loadUnitComparison(createAnonClient(), shortname, unitName, leftVersion, rightVersion);
}
