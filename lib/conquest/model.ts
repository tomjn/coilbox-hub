/**
 * The part of coilbox's `src/conquest/model.ts` the vendored generator needs,
 * and no more. Written here rather than vendored, which is the one exception in
 * this directory.
 *
 * Upstream's model.ts reaches campaign, scenario, play and content, and through
 * those the Tauri plugin bindings: about ten thousand lines the hub cannot
 * compile and has no use for. The generator itself touches almost none of it.
 * It imports two constants and three types, so those are what is here.
 *
 * The types cannot drift silently. They describe what `generateGalaxy` writes,
 * so a generator that starts writing a new field fails typecheck the moment
 * `bun run sync:vendor` brings it in. The constants can, since a changed value
 * still compiles and would quietly move every node's difficulty, so
 * `scripts/sync-vendor.ts` checks both against upstream on every CI run.
 */

/** The owner value for territory no faction holds. */
export const NEUTRAL = "neutral";

/** Difficulty bounds for a node (inclusive). */
export const MAX_DIFFICULTY = 5;

/** Which game a galaxy targets, by modinfo shortname. */
export interface GameRef {
  shortname: string;
  pinnedName?: string;
}

export interface Faction {
  id: string;
  name: string;
  /** `#rrggbb`. */
  color: string;
  aggression?: number;
  aiKey?: string;
  side?: string;
}

/** How a node's battle is set up. The generator only ever writes `mapName`,
 * and `substituteExcludedMaps` only ever clears `mapDownload`, so the rest of
 * upstream's authoring knobs are left out. */
export interface NodeBattleSpec {
  mapName: string;
  mapDownload?: undefined;
}

/** 2D for the procedural scatters, 3D for galaxies built from real stars. */
export type NodePos = [number, number] | [number, number, number];

/** A node's real star, when it came from the catalogue. */
export interface NodeStar {
  /** One spectral type per component, brightest first. */
  spectral: string[];
}

export interface GalaxyNode {
  id: string;
  name: string;
  pos: NodePos;
  star?: NodeStar;
  /** A faction id or {@link NEUTRAL}. */
  owner: string;
  kind?: "capital" | "normal";
  /** 1..5. */
  difficulty: number;
  battle: NodeBattleSpec;
}

export interface GalaxyDoc {
  schemaVersion: 1;
  id: string;
  type: "conquest-galaxy";
  title: string;
  description: string;
  game: GameRef;
  playerFactionId: string;
  playableFactionIds?: string[];
  factions: Faction[];
  nodes: GalaxyNode[];
  /** Undirected node-id pairs. */
  links: [string, string][];
  rules?: { graceTurns?: number; fogOfWar?: boolean };
  theme?: { skin?: "galaxy" | "theatre" };
  createdAt: string;
  updatedAt: string;
  generated?: {
    seed: number;
    nodeCount?: number;
    factionCount?: number;
    layout?: "scatter" | "spiral" | "clusters" | "ring" | "random" | "realstars";
    skin?: "galaxy" | "theatre";
    startingSystems?: number;
    fogOfWar?: boolean;
    radiusLy?: number;
  };
}
