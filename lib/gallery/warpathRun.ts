/**
 * The run map a warpath challenge produces, rebuilt here from its seed.
 *
 * The same approach as `conquestGalaxy`, over a different generator: the
 * payload carries the recipe, so the hub runs the generator the app runs. It is
 * vendored in `lib/runlite` and drift checked.
 *
 * A run is a forward-only graph of columns. Column 0 is the start, the last is
 * the boss, and the columns between hold two to four nodes each. The shape of
 * it, meaning how many nodes are in each column, what kind each one is and how
 * they join up, is a pure function of the seed. Installed content decides what
 * is inside a node, never where the node is. That was checked across 1080
 * combinations of seed, length, difficulty and ascension, with and without maps
 * and a build graph, and the shape never moved.
 *
 * What is deliberately not rebuilt is anything inside a node: the map a battle
 * is fought on, the units a reward offers, the wares in a shop. Those come from
 * installed content the hub does not have.
 */

import { generateRun } from "@/lib/runlite/generate";
import type { RogueliteRun, RunLength, RunNodeType } from "@/lib/runlite/model";

/** One node, positioned in a unit square. */
export interface RunStep {
  x: number;
  y: number;
  type: RunNodeType;
}

export interface RunShape {
  steps: RunStep[];
  /** Routes forward, as index pairs into {@link RunShape.steps}. */
  routes: [number, number][];
  /** Columns from start to boss, which is how long the run is. */
  columns: number;
}

/**
 * The knobs the generator needs, as coilbox validates them.
 *
 * The bounds mirror `parseRunSettings` in coilbox's `src/runlite/model.ts`,
 * which is what the app applies to a challenge before generating from it. An
 * unrecognised length falling back to `standard` is the one that matters most,
 * since length is the column count and so the whole shape of the map.
 */
interface RunKnobs {
  seed: number;
  length: RunLength;
  difficulty: number;
  ascension: number;
  factionId: string;
  side?: string;
}

const clamp = (v: number, lo: number, hi: number) =>
  Math.min(hi, Math.max(lo, v));

const num = (v: unknown, fallback: number) =>
  typeof v === "number" && Number.isFinite(v) ? v : fallback;

function readKnobs(payload: Record<string, unknown>): RunKnobs | null {
  if (payload.mode !== "warpath") return null;
  const s = payload.settings as Record<string, unknown> | undefined;
  if (typeof s !== "object" || s === null) return null;
  const game = s.game as Record<string, unknown> | undefined;
  // The game and faction are required here only because coilbox requires them.
  // Neither reaches the shape, but a challenge the app would reject is not one
  // the hub should draw a confident picture of.
  if (
    typeof game !== "object" ||
    game === null ||
    typeof game.shortname !== "string" ||
    game.shortname === "" ||
    typeof s.factionId !== "string" ||
    s.factionId === ""
  ) {
    return null;
  }
  return {
    seed: num(s.seed, 0),
    length:
      s.length === "quick" || s.length === "standard" || s.length === "long"
        ? s.length
        : "standard",
    difficulty: clamp(Math.round(num(s.difficulty, 2)), 1, 5),
    ascension: clamp(Math.round(num(s.ascension, 0)), 0, 99),
    factionId: s.factionId,
    side: typeof s.side === "string" && s.side !== "" ? s.side : undefined,
  };
}

/**
 * Lay the columns out left to right, each one centred on the middle.
 *
 * Spacing is set by the busiest column rather than by each column's own count,
 * so a column of two is not stretched to the same height as a column of four.
 * Rows stay in line across the map, the way a route map reads.
 */
function shapeOf(run: RogueliteRun): RunShape {
  const columns = Math.max(...run.nodes.map((n) => n.col)) + 1;
  const perColumn = new Map<number, number>();
  for (const node of run.nodes) {
    perColumn.set(node.col, (perColumn.get(node.col) ?? 0) + 1);
  }
  const busiest = Math.max(...perColumn.values());
  const gap = 1 / busiest;

  const index = new Map(run.nodes.map((node, i) => [node.id, i]));
  const steps = run.nodes.map((node) => {
    const count = perColumn.get(node.col) ?? 1;
    return {
      x: columns > 1 ? node.col / (columns - 1) : 0.5,
      y: 0.5 + (node.row - (count - 1) / 2) * gap,
      type: node.type,
    };
  });

  return {
    steps,
    routes: run.edges.flatMap(([from, to]) => {
      const a = index.get(from);
      const b = index.get(to);
      return a === undefined || b === undefined
        ? []
        : [[a, b] as [number, number]];
    }),
    columns,
  };
}

/**
 * The generator reads a map name for every battle node and has no empty-pool
 * fallback, so it needs one candidate to hand. Which map is passed does not
 * reach the shape: choosing one costs the same single random draw whether the
 * pool holds one map or forty, and the name only lands in a node the hub does
 * not draw.
 */
const PLACEHOLDER_MAPS = [{ name: "", size: 0 }];

/**
 * Rebuild a warpath challenge's run map, or null if it cannot be rebuilt.
 *
 * Null is the ordinary answer for a conquest challenge, for a payload from a
 * newer coilbox whose settings no longer parse, and for a seed the generator
 * refuses. The caller falls back to the settings it can read straight off the
 * payload.
 */
export function warpathRun(
  payload: Record<string, unknown>,
): RunShape | null {
  const knobs = readKnobs(payload);
  if (!knobs) return null;
  try {
    const run = generateRun({
      seed: knobs.seed,
      length: knobs.length,
      difficulty: knobs.difficulty,
      ascension: knobs.ascension,
      game: { shortname: "" },
      factionId: knobs.factionId,
      side: knobs.side,
      skin: "galaxy",
      maps: PLACEHOLDER_MAPS,
      // No build graph, so rewards and shops offer perks rather than unit
      // unlocks. That changes what is in a node, not which nodes there are.
      now: "",
    });
    return run.nodes.length > 0 ? shapeOf(run) : null;
  } catch {
    return null;
  }
}
