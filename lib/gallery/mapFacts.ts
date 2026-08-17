/**
 * What BAR's map list says about a map in words, for the caption under a
 * minimap (issue #176).
 *
 * A pack used to list its maps by name alone, which says nothing about what
 * playing on one is like. Size and the team shapes BAR lays boxes out for are
 * the two facts already on the entry the picture comes from, so they cost
 * nothing to say and are the two a person picking a map asks about first.
 *
 * Only BAR's own numbers. A map it does not certify has neither, and the
 * caption is then the name alone rather than a guess.
 */

import type { BarMap } from "@/lib/bar/maps";

/** How big the map is, in the 512 elmo squares Spring counts in and players
 * quote: a 6144 by 10240 elmo map is "12 x 20". Null when BAR's entry gives no
 * size. */
export function mapSizeLabel(map: BarMap | null): string | null {
  if (!map?.mapWidth || !map?.mapHeight) return null;
  return `${map.mapWidth} x ${map.mapHeight}`;
}

/**
 * The team shapes BAR draws start boxes for, in BAR's own order: two boxes of
 * eight is "8v8", four boxes of one is "1v1v1v1".
 *
 * Maps carry one or two of these in practice, so they are all listed rather
 * than summarised. Empty for a map BAR lists without boxes.
 */
export function mapTeamShapes(map: BarMap | null): string[] {
  return (map?.startboxesSet ?? [])
    .filter((set) => set.startboxes.length > 0 && set.maxPlayersPerStartbox > 0)
    .map((set) =>
      new Array(set.startboxes.length).fill(set.maxPlayersPerStartbox).join("v"),
    );
}

/** Size and team shapes as one line, or null when BAR says neither. */
export function mapFactsLabel(map: BarMap | null): string | null {
  const facts = [mapSizeLabel(map), ...mapTeamShapes(map)].filter(Boolean);
  return facts.length > 0 ? facts.join(" · ") : null;
}
