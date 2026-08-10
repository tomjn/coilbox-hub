/**
 * What an item's own payload contributes to the minimap beside it.
 *
 * BAR supplies the picture and the geometry, the item supplies the shape of the
 * battle: how many ally teams, how big the biggest one is, what colours they
 * are playing in, and how the engine will pick start positions. Only a preset
 * has any of that. A setup pack names a map and nothing else about how it gets
 * played, so it gets a bare picture.
 */

import type { BarMap } from "@/lib/bar/maps";
import {
  type StartLayout,
  startLayout,
  startPosLabel,
} from "@/lib/bar/startLayout";
import { participantColorCss, presetComposition } from "./presetPreview";

export interface MapOverlay {
  layout: StartLayout;
  /** Ally team index to CSS colour, in the same order the composition lists
   * the teams. */
  allyColors: string[];
  /** How start positions get chosen, or null when the payload does not say. */
  note: string | null;
}

const NOTHING: MapOverlay = { layout: { boxes: [], dots: [] }, allyColors: [], note: null };

export function mapOverlay(
  kind: string,
  container: unknown,
  map: BarMap,
): MapOverlay {
  const payload = (container as { payload?: unknown } | null)?.payload;
  if (kind !== "preset" || typeof payload !== "object" || payload === null) {
    return NOTHING;
  }

  const record = payload as Record<string, unknown>;
  const composition = presetComposition(record);
  if (!composition) return NOTHING;

  const { teams } = composition;
  const largestTeam = Math.max(...teams.map((t) => t.members.length));

  return {
    layout: startLayout(map, teams.length, largestTeam),
    // A team's colour is its first member's. Every participant picks their own,
    // so an ally team of four is four colours and one of them has to stand for
    // the box.
    allyColors: teams.map((t) => participantColorCss(t.members[0]?.color)),
    note: startPosLabel(record.startPosType),
  };
}
