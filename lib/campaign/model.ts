/**
 * One type from coilbox's `src/campaign/model.ts`, written here rather than
 * vendored.
 *
 * That file imports the container, scenario and play models, and through
 * `src/content/config.ts` the Tauri plugin bindings. The generators that reach
 * it only pass this type through to a field the hub never reads, so a
 * structural stand-in is enough and pulling the real one in is not possible.
 */

/** Where to fetch a map that is not installed. Opaque here. */
export type MapDownloadHint = Record<string, unknown>;
