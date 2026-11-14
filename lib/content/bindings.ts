/**
 * One type from coilbox's `src/content/bindings.ts`, written here rather than
 * vendored.
 *
 * That file is the Tauri plugin binding layer. It imports the plugin SDK, which
 * the hub does not have and would have nothing to talk to if it did. The
 * vendored `buildTree.ts` and `runlite/starterKit.ts` only name this type in
 * signatures, so the fields they read are all that is needed.
 */

/** A unit in a game's unit dataset, as far as `buildTree.ts` and
 *  `starterKit.ts` are concerned. */
export interface UnitDatasetEntry {
  name: string;
  buildOptions?: string[];
  /** Whether the unit can move (mobile unit) vs a static building. */
  mobile?: boolean;
  /** The unitdef's `minWaterDepth`. A naval yard declares one so it can only
   *  go in the sea. Absent means the dataset cannot say. */
  minWaterDepth?: number;
  /** Everything else the unitdef declares, untyped on purpose upstream. */
  stats?: Record<string, unknown>;
}
