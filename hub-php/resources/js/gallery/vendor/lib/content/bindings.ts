/**
 * One type from coilbox's `src/content/bindings.ts`, written here rather than
 * vendored.
 *
 * That file is the Tauri plugin binding layer. It imports the plugin SDK, which
 * the hub does not have and would have nothing to talk to if it did. The
 * vendored `buildTree.ts` only names this type in a signature, and the hub
 * never calls the function that takes it, so the two fields it reads are all
 * that is needed.
 */

/** A unit in a game's unit dataset, as far as `buildTree.ts` is concerned. */
export interface UnitDatasetEntry {
  name: string;
  buildOptions?: string[];
}
