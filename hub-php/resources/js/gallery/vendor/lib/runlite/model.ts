/**
 * The part of coilbox's `src/runlite/model.ts` the vendored warpath generator
 * needs, and no more. The same exception, and the same reasoning, as
 * `lib/conquest/model.ts`.
 *
 * Upstream's model.ts carries run progress, history, save files and their
 * parsers, and reaches the container and campaign models to do it. The
 * generator only needs the shapes it writes, so those are what is here. A
 * generator that starts writing a new field fails typecheck the moment it is
 * synced. `isBattleNode` is the one piece of logic restated here, and
 * `scripts/sync-vendor.ts` checks it against upstream.
 */

import type { MapDownloadHint } from "@/lib/campaign/model";
import type { GameRef, MapSkin } from "@/lib/conquest/model";
import type { PlanetId } from "@/lib/conquest/planets";

export type RunLength = "quick" | "standard" | "long";

export type RunSkin = MapSkin;

/** Node kinds on the run graph. `battle`, `elite` and `boss` launch a
 * skirmish. The rest resolve on the map. */
export type RunNodeType =
  | "start"
  | "battle"
  | "elite"
  | "boss"
  | "reward"
  | "event"
  | "shop";

/** True for the node kinds that launch a skirmish. */
export function isBattleNode(type: RunNodeType): boolean {
  return type === "battle" || type === "elite" || type === "boss";
}

export interface EncounterSpec {
  mapName: string;
  mapDownload?: MapDownloadHint;
  /**
   * The map this encounter was meant to be fought on, when `mapName` is a
   * stand-in. Set when an imported challenge names a map this install cannot
   * offer, so the difference is visible rather than silent (issue #1393
   * upstream).
   */
  mapSubstitutedFrom?: string;
  enemyAiCount: number;
  enemyAiKey?: string;
  startPosType?: number;
  modOptionValues?: Record<string, string>;
  handicap: number;
  /** Shared tech ceiling for this encounter, 1..5 by depth. */
  techTier: number;
}

export type PerkKind = "advantage" | "income";

export interface Perk {
  kind: PerkKind;
  value: number;
  label: string;
}

export type RewardOption =
  | { kind: "unlock"; unit: string; unitName: string; opens: string[] }
  | { kind: "perk"; perk: Perk };

export interface RewardSpec {
  title: string;
  options: RewardOption[];
}

export interface EventChoice {
  label: string;
  detail?: string;
  hull?: number;
  salvage?: number;
  perk?: Perk;
  unlock?: string;
}

export interface EventSpec {
  title: string;
  body: string;
  choices: EventChoice[];
}

export interface ShopOffer {
  cost: number;
  option: RewardOption;
}

export interface ShopSpec {
  offers: ShopOffer[];
  restHull?: number;
  restCost?: number;
}

export interface RunNode {
  id: string;
  type: RunNodeType;
  /** Forward rank, 0 is the start. */
  col: number;
  /** Position within the column. */
  row: number;
  battle?: EncounterSpec;
  reward?: RewardSpec;
  event?: EventSpec;
  shop?: ShopSpec;
}

/** How a challenge names the hand-made map it is played on. Upstream keeps
 * this in `src/challenge/mapRef.ts`. */
export interface HandmadeMapRef {
  source: "handmade";
  /** The map's id in the hand-made map library. */
  id: string;
  fingerprint?: string;
  title?: string;
}

/** How a run across a land map finds that map again. A generated one is
 * rebuilt from these settings, and a hand-made one is looked up by its id. */
export type RunMapRef =
  | {
      source: "generated";
      /** Which generator made it: `territories` or `cities`. */
      style: string;
      /** The map's own seed, which need not be the run's. */
      seed: number;
      nodeCount: number;
      layout?: string;
      /** Absent is Temperate. */
      planet?: PlanetId;
    }
  | HandmadeMapRef;

/** A directed forward edge, `from.col < to.col`. */
export type RunEdge = [string, string];

export interface RunSettings {
  seed: number;
  length: RunLength;
  /** 1..5. */
  difficulty: number;
  ascension: number;
  game: GameRef;
  factionId: string;
  side?: string;
  skin: RunSkin;
  map?: RunMapRef;
}

export interface RunProgress {
  currentNodeId: string;
  visited: string[];
  hull: number;
  maxHull: number;
  salvage: number;
  unlockedUnits: string[];
  perks: Perk[];
  status: "active" | "won" | "lost";
}

export interface RogueliteRun {
  schemaVersion: 1;
  type: "roguelite-run";
  name: string;
  settings: RunSettings;
  startUnit?: string;
  nodes: RunNode[];
  edges: RunEdge[];
  progress: RunProgress;
  history: unknown[];
  createdAt: string;
  updatedAt: string;
}
