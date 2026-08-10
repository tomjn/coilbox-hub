/**
 * Finding an item's map in BAR's list by name.
 *
 * An item's `map_name` is the spring name coilbox read off the installed map,
 * and BAR keys its list on the same thing, so an exact match is the normal
 * case. It misses when the two ran different builds of one map: 224 of BAR's
 * 225 spring names end in a version, and a preset made on 1.6 names a map the
 * list only knows at 1.8.
 *
 * So a miss falls back to comparing names with the version taken off. That is
 * safe here because the version is the only thing separating them: stripping it
 * across the whole list leaves 224 distinct names, the single clash being
 * Supreme Isthmus at v1.7 and v2.1, which is one map twice. The neighbouring
 * risk, "All That Glitters" against "All That Glitters Extended", stays two
 * names because Extended is not a version.
 */

import type { BarMap } from "./maps";

/** A trailing version, with or without a `v`, however it is joined on:
 * `AcidicQuarry 5.17`, `Ancient Vault v1.4`, `Altair_Crossing_V4.1`. */
const VERSION_SUFFIX = /[ _-]*v?\d+(\.\d+)*$/i;

/** The version a name ends in, as numbers, so two builds of one map can be
 * ordered. Empty when the name carries no version. */
function versionParts(name: string): number[] {
  const found = name.match(VERSION_SUFFIX)?.[0] ?? "";
  return found.replace(/^[ _-]*v?/i, "").split(".").filter(Boolean).map(Number);
}

/** Later of two versions, comparing part by part so 1.10 beats 1.9. A name
 * with no version loses to one that has it. */
function isNewer(a: number[], b: number[]): boolean {
  for (let i = 0; i < Math.max(a.length, b.length); i++) {
    const [x, y] = [a[i] ?? -1, b[i] ?? -1];
    if (x !== y) return x > y;
  }
  return false;
}

/**
 * A map name with its version and punctuation removed, for comparing two
 * spellings of the same map. Not for display.
 */
export function baseMapName(name: string): string {
  return name
    .trim()
    .replace(VERSION_SUFFIX, "")
    .toLowerCase()
    .replace(/[^a-z0-9]/g, "");
}

/**
 * The map in `maps` that `name` refers to, or null when BAR does not list it.
 * Exact spring name wins outright. Otherwise the newest build sharing the
 * version-stripped name, so an item published against an older map still shows
 * the right picture.
 */
export function matchMapName(name: string, maps: BarMap[]): BarMap | null {
  const wanted = name.trim();
  if (!wanted) return null;

  const exact = maps.find((m) => m.springName === wanted);
  if (exact) return exact;

  const base = baseMapName(wanted);
  if (!base) return null;

  let best: BarMap | null = null;
  for (const map of maps) {
    if (baseMapName(map.springName) !== base) continue;
    if (!best || isNewer(versionParts(map.springName), versionParts(best.springName))) {
      best = map;
    }
  }
  return best;
}
