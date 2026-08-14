/**
 * The hash of the encoded bytes, computed by the hub (issue #154).
 *
 * `hash` is the leaf of the content addressed path, so whoever picks it picks
 * where the picture lands. On the staging tier that did not matter, because
 * Blob appends a suffix nobody can derive and the row stores what came back.
 * Promotion (#111) recomputes the path from the row and commits the bytes into
 * a public git history, so it matters there: a map path carries no map name, so
 * `maps/minimap/<hash>.webp` is a filename made entirely of the hash, and a
 * declared one is an uploader choosing which existing picture to overwrite.
 *
 * So the hub computes it and the declaration no longer carries it at all. The
 * bytes are already in memory by the time this runs, the upload route already
 * reads their header, and hashing them costs no round trip and no advanced
 * operation, which is what lets it sit with the other free refusals rather than
 * after the write.
 *
 * ## Why SHA-256, lowercase hex
 *
 * Because it has to be the same string coilbox produces. coilbox's asset
 * pipeline spec specifies sha256 for both hashes, and the one content addressed
 * store coilbox already ships keys on `format!("{:x}.{ext}", Sha256::digest(..))`,
 * which is 64 lowercase hex characters with no prefix and no truncation. The
 * exporter that will post here is not written yet (coilbox#1639), so this
 * matches the convention rather than a call site, and the convention is the only
 * one in that repo that crosses a machine boundary.
 *
 * The shape is also what the hub needs anyway. 64 hex characters satisfies
 * `SAFE_SEGMENT` in `./path` without escaping, and sits well inside the 128
 * character check on `public.asset.hash`.
 *
 * Web Crypto rather than `node:crypto`, so this is the same call in a route
 * handler, a script and a test, and it needs no dependency. Web Crypto offers
 * SHA-1, SHA-256, SHA-384 and SHA-512 and nothing else, which rules out the
 * blake3 that a content addressed store might otherwise reach for. That is not
 * a loss here: this is a name for some bytes and not a message digest anybody
 * authenticates, and SHA-256 is what the other end already computes.
 *
 * ## What this is not
 *
 * Not `source_hash`. That one is over the raw archive bytes and the hub never
 * sees an archive, so it cannot be verified here or anywhere else and stays a
 * client's word. That is fine: `source_hash` names no object and decides no
 * path. It answers "have you already got these source bytes" (#103) and feeds
 * the anomaly check (#116), and lying about it costs the liar a duplicate
 * upload. The two are easy to confuse and only one of them is checkable.
 */

/** The encoded bytes, as 64 lowercase hex characters. */
export async function encodedHash(bytes: ArrayBuffer): Promise<string> {
  const digest = await crypto.subtle.digest("SHA-256", bytes);
  return Array.from(new Uint8Array(digest))
    .map((byte) => byte.toString(16).padStart(2, "0"))
    .join("");
}
