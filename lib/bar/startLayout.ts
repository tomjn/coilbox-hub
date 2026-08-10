/**
 * Turning a preset's team shape plus BAR's map data into something drawable
 * over a minimap.
 *
 * What this can and cannot say is worth being exact about. A coilbox preset
 * carries participants, a map, a start position mode and mod options, and
 * nothing else (`SkirmishDraft` in coilbox's `src/play/drafts.ts`). It does not
 * carry start boxes, and `toBattleConfig` writes `allyTeams` with no
 * `StartRect`, so the engine picks positions on its own from the mode. Whatever
 * is drawn here is therefore BAR's layout for a battle of this shape, not a
 * claim about where these teams will land, and the caller says so in words.
 *
 * If a preset ever does carry its own boxes, they are the canonical answer and
 * belong ahead of everything in this file.
 */

import type { BarMap, BarStartBoxSet } from "./maps";

/** A start box as a fraction of the map, top left origin, ready for
 * percentages. BAR's own coordinates are integers on a 0..200 grid. */
export interface BoxRect {
  left: number;
  top: number;
  width: number;
  height: number;
}

/** A spawn point as a fraction of the map, with what BAR expects it to be used
 * for (`front`, `air`, `tech`, `sea`, and pairs like `air/front`). */
export interface SpawnDot {
  x: number;
  y: number;
  role?: string;
  side: number;
}

export interface StartLayout {
  boxes: BoxRect[];
  dots: SpawnDot[];
}

/** BAR start boxes are integers on a 0..200 grid, the same grid TASServer's
 * ADDSTARTRECT uses and coilbox's own lobby overlay draws on. */
const GRID = 200;

/** Spring map dimensions are counted in 512 elmo squares. */
const ELMOS_PER_UNIT = 512;

/** The set of boxes BAR draws for a battle of this shape, or null when the map
 * has none for it. Box count has to match the number of teams exactly. Among
 * the sets that do, the tightest one that still seats the biggest team wins,
 * falling back to the roomiest set when no set seats it. */
export function pickBoxSet(
  map: BarMap,
  teamCount: number,
  largestTeam: number,
): BarStartBoxSet | null {
  const sized = (map.startboxesSet ?? []).filter(
    (set) => set.startboxes.length === teamCount,
  );
  if (sized.length === 0) return null;

  const seats = sized.filter((set) => set.maxPlayersPerStartbox >= largestTeam);
  const pool = seats.length > 0 ? seats : sized;
  const best = seats.length > 0 ? Math.min : Math.max;
  const target = best(...pool.map((set) => set.maxPlayersPerStartbox));
  return pool.find((set) => set.maxPlayersPerStartbox === target) ?? null;
}

/** A box's two corners in either order, as a fraction of the map. */
function toRect(poly: { x: number; y: number }[]): BoxRect | null {
  if (poly.length < 2) return null;
  const xs = poly.map((p) => p.x);
  const ys = poly.map((p) => p.y);
  const left = Math.min(...xs) / GRID;
  const top = Math.min(...ys) / GRID;
  return {
    left,
    top,
    width: Math.max(...xs) / GRID - left,
    height: Math.max(...ys) / GRID - top,
  };
}

/**
 * BAR's named spawn points for a two sided battle of exactly this size.
 *
 * Gated hard on an exact player count because BAR's layouts are per team size
 * and are in practice only 8v8. Plotting an eight player layout for a team of
 * four would be inventing six positions the data never described.
 */
function pickDots(
  map: BarMap,
  teamCount: number,
  largestTeam: number,
): SpawnDot[] {
  const startPos = map.startPos;
  if (!startPos?.positions || teamCount !== 2) return [];

  const layout = (startPos.team ?? []).find(
    (t) => t.playersPerTeam === largestTeam && t.sides.length === teamCount,
  );
  if (!layout) return [];

  const width = (map.mapWidth ?? 0) * ELMOS_PER_UNIT;
  const height = (map.mapHeight ?? 0) * ELMOS_PER_UNIT;
  if (!width || !height) return [];

  return layout.sides.flatMap((side, index) =>
    side.starts.flatMap((start) => {
      const point = startPos.positions[start.spawnPoint];
      if (!point) return [];
      return [
        {
          x: point.x / width,
          y: point.y / height,
          role: start.role,
          side: index,
        },
      ];
    }),
  );
}

/**
 * Everything drawable for this map and this team shape. Both halves can be
 * empty, which is a bare minimap and no overlay.
 */
export function startLayout(
  map: BarMap,
  teamCount: number,
  largestTeam: number,
): StartLayout {
  const set = pickBoxSet(map, teamCount, largestTeam);
  const boxes = (set?.startboxes ?? []).flatMap((box) => toRect(box.poly) ?? []);
  return { boxes, dots: pickDots(map, teamCount, largestTeam) };
}

/** How the engine will choose start positions, in the same words coilbox's own
 * setup screen uses (`START_POS_OPTIONS` in its `GameOptionsPanel`). */
export function startPosLabel(startPosType: unknown): string | null {
  if (startPosType === 0) return "Fixed map start positions";
  if (startPosType === 1) return "Random start positions";
  if (startPosType === 2) return "Players choose in game";
  return null;
}
