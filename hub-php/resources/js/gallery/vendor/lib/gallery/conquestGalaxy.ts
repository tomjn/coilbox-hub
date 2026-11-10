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
 * name of each system, the map on each system, and the names and colours of a
 * game's lore factions. Those are read out of the payload when the challenge
 * carries them and left blank when it does not.
 *
 * Read and not regenerated, even though the payload names the game and the
 * catalog holds naming pools for a few of them. Reproducing the pools would be
 * right for those few and confidently wrong for every other game, including any
 * that takes its factions from what is installed. A galaxy wearing the wrong
 * names is worse than one wearing none (issue #397).
 */

import { type NodeMaps, parseNodeMaps } from "@/lib/challenge/nodeMaps";
import { generateGalaxy } from "@/lib/conquest/generate";
import type { GalaxyDoc, NodePos } from "@/lib/conquest/model";
import { NEUTRAL } from "@/lib/conquest/model";

/** One system, positioned in a unit square with the aspect ratio kept. */
export interface GalaxySystem {
  x: number;
  y: number;
  /** Index into {@link GalaxyShape.factions}, or null for neutral. */
  faction: number | null;
  capital: boolean;
  /** What the game called this system. Absent when the payload does not say. */
  name?: string;
  /** The map this system resolved to (coilbox#1393). Absent when the payload
   *  does not say, and when it names a system this rebuild does not have. */
  map?: string;
}

/** A faction as it is drawn: always a colour, a name only when the payload
 *  carries one. */
export interface GalaxyFaction {
  color: string;
  name?: string;
}

export interface GalaxyShape {
  systems: GalaxySystem[];
  /** Jump lanes, as index pairs into {@link GalaxyShape.systems}. */
  lanes: [number, number][];
  /** Player first, then enemies. The payload's colour where it gives one, the
   *  generator's default palette slot where it does not. */
  factions: GalaxyFaction[];
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

/**
 * The part of a galaxy the seed cannot reproduce, as the challenge recorded it.
 *
 * Every field is optional and every field is absent on a challenge shared
 * before coilbox published any of this, which is why nothing here is allowed to
 * stop a galaxy being drawn.
 */
interface ResolvedContent {
  /** System name by node id. */
  names: NodeMaps;
  /** Map name by node id (coilbox#1393). */
  maps: NodeMaps;
  /** Player first, then enemies, in the order the generator builds them. Null
   *  in a slot the payload did not usably fill, so the rest keep their
   *  positions rather than shuffling up into the wrong faction. */
  factions: ({ name: string; color?: string } | null)[];
}

const HEX_COLOR = /^#[0-9a-f]{6}$/i;

/**
 * The factions a challenge records, player first.
 *
 * Read by position rather than by id, because that is the order the generator
 * builds them in on both sides: the player is always first and the enemies
 * follow. An entry with no usable name leaves its slot empty rather than being
 * skipped over, so a bad second entry cannot hand the second faction's name to
 * the third.
 *
 * Four is the most the generator ever builds, a player plus three enemies.
 */
function readFactions(value: unknown): ResolvedContent["factions"] {
  if (!Array.isArray(value)) return [];
  return value.slice(0, 4).map((entry) => {
    if (typeof entry !== "object" || entry === null) return null;
    const { name, color } = entry as Record<string, unknown>;
    if (typeof name !== "string" || name.trim() === "") return null;
    return {
      name: name.trim(),
      color:
        typeof color === "string" && HEX_COLOR.test(color) ? color : undefined,
    };
  });
}

/**
 * `parseNodeMaps` reads both by-node objects, because both are the same thing:
 * one bounded string per node id. It is coilbox's own reader, vendored, so the
 * hub drops exactly what the app drops and stops counting where the app stops.
 */
function readContent(settings: Record<string, unknown>): ResolvedContent {
  return {
    names: parseNodeMaps(settings.nodeNames) ?? {},
    maps: parseNodeMaps(settings.nodeMaps) ?? {},
    factions: readFactions(settings.factions),
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

function shapeOf(galaxy: GalaxyDoc, content: ResolvedContent): GalaxyShape {
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
      // The generated node's own name is not used. It comes from the built-in
      // pool, which is not the pool the galaxy was named from.
      name: content.names[node.id],
      map: content.maps[node.id],
    })),
    lanes: galaxy.links.flatMap(([a, b]) => {
      const from = index.get(a);
      const to = index.get(b);
      return from === undefined || to === undefined
        ? []
        : [[from, to] as [number, number]];
    }),
    factions: galaxy.factions.map((f, i) => {
      const said = content.factions[i];
      return { color: said?.color ?? f.color, name: said?.name };
    }),
  };
}

/**
 * Rebuild a conquest challenge's galaxy, or null if it cannot be rebuilt.
 *
 * Null is the ordinary answer for a warpath challenge, for a payload from a
 * newer coilbox whose settings no longer parse, and for a seed the generator
 * refuses. The caller falls back to the counts it can read straight off the
 * payload, so a challenge shows less rather than nothing.
 *
 * It is also the answer when the payload names systems this rebuild does not
 * have, or leaves one of its systems unnamed. Coilbox writes `nodeNames` from
 * the galaxy's own nodes, all of them or none, so a set that does not match
 * means the two galaxies are not the same galaxy and the vendored generator has
 * fallen behind. That is the check coilbox#1393 asked a by-node payload for:
 * drawing the graph anyway would be a confident picture of something else.
 */
export function conquestGalaxy(
  payload: Record<string, unknown>,
): GalaxyShape | null {
  const knobs = readKnobs(payload);
  if (!knobs) return null;
  const content = readContent(payload.settings as Record<string, unknown>);
  try {
    const galaxy = generateGalaxy({
      seed: knobs.seed,
      game: { shortname: "" },
      // No maps and no naming pools: everything the graph is made of is
      // decided before the generator reads either, and everything they would
      // have decided is read off the payload instead.
      maps: [],
      nodeCount: knobs.nodeCount,
      factionCount: knobs.factionCount,
      layout: knobs.layout,
      radiusLy: knobs.radiusLy,
      startingSystems: knobs.startingSystems,
    });
    if (galaxy.nodes.length === 0) return null;
    const named = Object.keys(content.names).length;
    if (
      named > 0 &&
      (named !== galaxy.nodes.length ||
        !galaxy.nodes.every((node) => node.id in content.names))
    ) {
      return null;
    }
    return shapeOf(galaxy, content);
  } catch {
    return null;
  }
}
