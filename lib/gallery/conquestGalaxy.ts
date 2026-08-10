/**
 * The galaxy a conquest challenge produces, rebuilt here from its seed.
 *
 * A challenge payload carries the recipe and not the result, so the only way to
 * show the thing a person would recognise is to run the generator the app runs.
 * That generator is vendored in `lib/conquest` and drift checked, because a
 * copy of it that fell behind would keep drawing the old galaxy with nothing to
 * catch it.
 *
 * Same seed, same graph. Positions, lanes, capitals and starting territory are
 * all settled before the generator first touches installed content, so passing
 * it no maps and no naming pools gives the graph every machine gets. The one
 * exception is `limitToNamed`, an opt-in in a profile or a game's branding
 * entry that caps a galaxy to the size of its named-star pool. Nothing in
 * coilbox's catalog sets it.
 *
 * What is deliberately not rebuilt is anything installed content decides: the
 * map on each system, and the names and colours of a game's lore factions.
 * Factions are drawn in the generator's own default palette and identified by
 * position rather than name. Maps are coilbox#1393, which would put the
 * resolved map names in the payload. Until that lands the hub does not know
 * them and has no business guessing.
 */

import { generateGalaxy } from "@/lib/conquest/generate";
import type { GalaxyDoc, NodePos } from "@/lib/conquest/model";
import { NEUTRAL } from "@/lib/conquest/model";

/** One system, positioned in a unit square with the aspect ratio kept. */
export interface GalaxySystem {
  x: number;
  y: number;
  /** Index into {@link GalaxyShape.factionColors}, or null for neutral. */
  faction: number | null;
  capital: boolean;
}

export interface GalaxyShape {
  systems: GalaxySystem[];
  /** Jump lanes, as index pairs into {@link GalaxyShape.systems}. */
  lanes: [number, number][];
  /** Player first, then enemies, in the generator's default palette. */
  factionColors: string[];
}

/**
 * The knobs the generator needs, as coilbox validates them.
 *
 * The bounds mirror `parseConquestChallengeSettings` in coilbox's
 * `src/conquest/challenge.ts`, which is what the app applies to a challenge
 * before generating from it. They are not vendored, because that function is
 * module private there, and because it is validation rather than the algorithm
 * this file exists to reproduce. `radiusLy` is the one that would actually
 * bite: the generator does not bound it itself, so an out-of-range value would
 * build a galaxy of a different size than the app builds.
 */
interface ChallengeKnobs {
  seed: number;
  nodeCount: number;
  factionCount: number;
  layout: "scatter" | "spiral" | "clusters" | "ring" | "random" | "realstars";
  radiusLy?: number;
  startingSystems?: number;
}

const LAYOUTS: readonly ChallengeKnobs["layout"][] = [
  "scatter",
  "spiral",
  "clusters",
  "ring",
  "random",
  "realstars",
];

const clamp = (v: number, lo: number, hi: number) =>
  Math.min(hi, Math.max(lo, v));

const finite = (v: unknown): v is number =>
  typeof v === "number" && Number.isFinite(v);

function readKnobs(payload: Record<string, unknown>): ChallengeKnobs | null {
  if (payload.mode !== "conquest") return null;
  const s = payload.settings as Record<string, unknown> | undefined;
  if (typeof s !== "object" || s === null) return null;
  // The game is not read. It only reaches the generated title and description,
  // neither of which is drawn, and requiring it would throw away a galaxy the
  // hub could otherwise show.
  if (!finite(s.seed) || !finite(s.nodeCount) || !finite(s.factionCount)) {
    return null;
  }
  return {
    seed: s.seed,
    nodeCount: clamp(Math.round(s.nodeCount), 5, 80),
    factionCount: clamp(Math.round(s.factionCount), 1, 3),
    layout: LAYOUTS.includes(s.layout as ChallengeKnobs["layout"])
      ? (s.layout as ChallengeKnobs["layout"])
      : "scatter",
    radiusLy: finite(s.radiusLy) ? clamp(s.radiusLy, 1, 25) : undefined,
    startingSystems: finite(s.startingSystems)
      ? clamp(Math.round(s.startingSystems), 1, 4)
      : undefined,
  };
}

/** Scatter positions are 2D and real-star ones are 3D light years. Both are
 * drawn on the same plane the app draws them on, which is x against y. */
const plane = (pos: NodePos): [number, number] => [pos[0], pos[1]];

/**
 * Fit the systems into a unit square without stretching them. Both axes are
 * scaled by the larger span, so a galaxy wider than it is tall stays that way
 * instead of being squared up into a different shape.
 */
function normalise(points: [number, number][]): [number, number][] {
  const xs = points.map((p) => p[0]);
  const ys = points.map((p) => p[1]);
  const minX = Math.min(...xs);
  const minY = Math.min(...ys);
  const spanX = Math.max(...xs) - minX;
  const spanY = Math.max(...ys) - minY;
  const span = Math.max(spanX, spanY);
  // A single system, or several stacked on one point, has no span to divide by.
  if (span === 0) return points.map(() => [0.5, 0.5]);
  const padX = (span - spanX) / 2;
  const padY = (span - spanY) / 2;
  return points.map(([x, y]) => [
    (x - minX + padX) / span,
    (y - minY + padY) / span,
  ]);
}

function shapeOf(galaxy: GalaxyDoc): GalaxyShape {
  const index = new Map(galaxy.nodes.map((node, i) => [node.id, i]));
  const factionIndex = new Map(galaxy.factions.map((f, i) => [f.id, i]));
  const positions = normalise(galaxy.nodes.map((node) => plane(node.pos)));

  return {
    systems: galaxy.nodes.map((node, i) => ({
      x: positions[i][0],
      y: positions[i][1],
      faction:
        node.owner === NEUTRAL ? null : (factionIndex.get(node.owner) ?? null),
      capital: node.kind === "capital",
      // SEAM FOR coilbox#1393: when a challenge payload names the map each
      // system resolved to, it belongs on this object, read from the payload
      // rather than generated. Nothing else here has to change.
    })),
    lanes: galaxy.links.flatMap(([a, b]) => {
      const from = index.get(a);
      const to = index.get(b);
      return from === undefined || to === undefined
        ? []
        : [[from, to] as [number, number]];
    }),
    factionColors: galaxy.factions.map((f) => f.color),
  };
}

/**
 * Rebuild a conquest challenge's galaxy, or null if it cannot be rebuilt.
 *
 * Null is the ordinary answer for a warpath challenge, for a payload from a
 * newer coilbox whose settings no longer parse, and for a seed the generator
 * refuses. The caller falls back to the counts it can read straight off the
 * payload, so a challenge shows less rather than nothing.
 */
export function conquestGalaxy(
  payload: Record<string, unknown>,
): GalaxyShape | null {
  const knobs = readKnobs(payload);
  if (!knobs) return null;
  try {
    const galaxy = generateGalaxy({
      seed: knobs.seed,
      game: { shortname: "" },
      // No maps and no naming pools: everything the graph is made of is
      // decided before the generator reads either.
      maps: [],
      nodeCount: knobs.nodeCount,
      factionCount: knobs.factionCount,
      layout: knobs.layout,
      radiusLy: knobs.radiusLy,
      startingSystems: knobs.startingSystems,
    });
    return galaxy.nodes.length > 0 ? shapeOf(galaxy) : null;
  } catch {
    return null;
  }
}
