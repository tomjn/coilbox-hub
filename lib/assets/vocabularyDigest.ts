import source from "./vendor/source.json";

/**
 * What the discovery document serves so an already shipped client can find out
 * it is behind (#165).
 *
 * The sha256 of the vendored file's bytes, lowercase hex. Not a
 * re-serialisation, not sorted keys, not a canonical form: vendoring makes the
 * bytes identical on both sides, so hashing the bytes is the one definition
 * that needs no agreement about formatting.
 *
 * Read out of the vendor record rather than computed, because a route runs from
 * a bundle and cannot read the file's bytes back. `bun run sync:vendor` writes
 * it, `bun run check:vendor` fails if it is not the sha256 of the file, and
 * `vocabulary.test.ts` says the same thing again, because serving a stale
 * digest tells every client it is behind when it is not.
 *
 * Advisory only. A client compares this and reports a mismatch. The refusals
 * that matter still come from the upload route, which checks the bytes.
 */
export const ASSET_VOCABULARY_DIGEST = `sha256:${source.sha256["asset-vocabulary.json"]}`;
