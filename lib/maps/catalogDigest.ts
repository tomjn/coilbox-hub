import source from "./vendor/source.json";

/**
 * What the discovery document serves so an already shipped client can find out
 * that what it holds is not what the hub agreed to (#185).
 *
 * The sha256 of the vendored file's bytes, lowercase hex. Not a
 * re-serialisation, not sorted keys, not a canonical form: vendoring makes the
 * bytes identical on both sides, so hashing the bytes is the one definition
 * that needs no agreement about formatting.
 *
 * Read out of the vendor record rather than computed, because a route runs from
 * a bundle and cannot read the file's bytes back. `bun run sync:vendor` writes
 * it, `bun run check:vendor` fails if it is not the sha256 of the file, and
 * `catalogDigest.test.ts` says the same thing again, because serving a stale
 * digest tells every client it is behind when it is not.
 *
 * A separate constant from `ASSET_VOCABULARY_DIGEST` rather than a second field
 * on one, because the two files answer different questions and a client acts
 * differently on each. A moved asset vocabulary means the client no longer
 * knows how to encode a picture, and it should stop uploading. A moved catalog
 * means it no longer knows what facts to report about a map. Behind one digest
 * a change to a clustering parameter would read as both, and the client would
 * stop uploading pictures over a number that says nothing about bytes. The two
 * files are vendored as separate groups for the same reason, so each has its own
 * `source.json` and the digests move independently.
 */
export const MAP_CATALOG_DIGEST = `sha256:${source.sha256["map-catalog.json"]}`;
