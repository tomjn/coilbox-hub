/**
 * Beyond All Reason's validated map list, the only picture of a map the hub can
 * get. Nothing here is stored: a published item carries a map name and no
 * image, and BAR publishes both a preview thumbnail and the start geometry for
 * every map it certifies, keyed by the same spring name coilbox writes into a
 * preset.
 *
 * The same list is what coilbox itself reads (`BAR_MAPS_URL` in
 * `crates/tauri-plugin-coilbox-downloads/src/sources.rs`), so the two agree on
 * what a map is called.
 *
 * Only the fields the hub draws are typed. The list is around a megabyte and
 * near static, so it is fetched with a day's cache and deduped per render pass.
 * A failure resolves to an empty list, because a missing picture is a page
 * without a picture rather than a page that fails.
 */

import { cache } from "react";
import { matchMapName } from "./mapName";

/** Two opposite corners of a start box, on BAR's 0..200 grid. Which corner
 * comes first is not guaranteed, so callers take the min and max. */
export interface BarStartBox {
  poly: { x: number; y: number }[];
}

/** One team shape a map supports: how many boxes, and how many players BAR
 * expects to fit in each. */
export interface BarStartBoxSet {
  maxPlayersPerStartbox: number;
  startboxes: BarStartBox[];
}

/** A named spawn point in world elmos. Divide by `mapWidth * 512` (or height)
 * to get a fraction of the map. */
export interface BarSpawnPoint {
  x: number;
  y: number;
}

/** One side of a team layout: which spawn points that side takes, and what
 * each one is for. */
export interface BarStartSide {
  starts: { role?: string; spawnPoint: string }[];
}

export interface BarStartPos {
  positions: Record<string, BarSpawnPoint>;
  team: { playersPerTeam: number; sides: BarStartSide[] }[];
}

export interface BarMap {
  springName: string;
  displayName: string;
  /** In 512 elmo units, so a width of 12 is a 6144 elmo map. */
  mapWidth?: number;
  mapHeight?: number;
  images?: { preview?: string };
  startboxesSet?: BarStartBoxSet[];
  /** Only around a quarter of maps have this, and almost all of those describe
   * an 8v8 layout and nothing else. */
  startPos?: BarStartPos;
}

const BAR_MAPS_URL =
  "https://maps-metadata.beyondallreason.dev/latest/lobby_maps.validated.json";

const ONE_DAY = 86400;

/**
 * The list, or nothing at all when BAR cannot be reached. `cache` dedupes the
 * parse across a page and its metadata, which read it separately.
 */
export const barMaps = cache(async (): Promise<BarMap[]> => {
  try {
    const res = await fetch(BAR_MAPS_URL, { next: { revalidate: ONE_DAY } });
    if (!res.ok) return [];
    const parsed = await res.json();
    return Array.isArray(parsed) ? (parsed as BarMap[]) : [];
  } catch {
    return [];
  }
});

/** The BAR map an item's `map_name` refers to, or null for a map BAR does not
 * list (anything custom, and anything it has not certified). */
export async function findBarMap(
  mapName: string | null,
): Promise<BarMap | null> {
  if (!mapName) return null;
  return matchMapName(mapName, await barMaps());
}
