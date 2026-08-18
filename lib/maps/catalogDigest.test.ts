import { expect, test } from "bun:test";
import { ASSET_VOCABULARY_DIGEST } from "@/lib/assets/vocabularyDigest";
import { MAP_CATALOG_DIGEST } from "./catalogDigest";

const VENDORED = "lib/maps/vendor/map-catalog.json";

/** The digest a client computes over its own copy: the sha256 of the bytes,
 * lowercase hex. Written out here rather than imported, because a helper shared
 * with the code under test would let both agree on the wrong answer. */
async function sha256Of(bytes: BufferSource): Promise<string> {
  const digest = await crypto.subtle.digest("SHA-256", bytes);
  return Array.from(new Uint8Array(digest))
    .map((byte) => byte.toString(16).padStart(2, "0"))
    .join("");
}

/**
 * The digest is recorded by `bun run sync:vendor` rather than computed, because
 * a route runs from a bundle and cannot read the file's bytes back. Recorded
 * means it can go stale, and a stale one tells every client it is behind when
 * it is not, so this is what says it has not.
 */
test("the recorded digest is the sha256 of the vendored bytes", async () => {
  const bytes = await Bun.file(VENDORED).bytes();

  expect(MAP_CATALOG_DIGEST).toBe(`sha256:${await sha256Of(bytes)}`);
});

/**
 * The comparison a client makes is over bytes, so a copy that has drifted by a
 * single character fails it. This is the property the whole scheme rests on: a
 * client that has to guess whether a difference matters would end up tolerating
 * one that did.
 */
test("a drifted copy does not match the digest served", async () => {
  const text = await Bun.file(VENDORED).text();
  const drifted = text.replace('"minSeparationElmos": 96', '"minSeparationElmos": 97');

  expect(drifted).not.toBe(text);
  expect(`sha256:${await sha256Of(new TextEncoder().encode(drifted))}`).not.toBe(
    MAP_CATALOG_DIGEST,
  );
});

/**
 * The two files are vendored as separate groups so their digests move
 * independently (#185). If they ever shared a record this would be the first
 * thing to go, and bumping a clustering parameter would tell every client its
 * asset vocabulary had moved, whose correct response is to stop uploading
 * pictures.
 */
test("the catalog digest is not the asset vocabulary digest", () => {
  expect(MAP_CATALOG_DIGEST).not.toBe(ASSET_VOCABULARY_DIGEST);
  expect(MAP_CATALOG_DIGEST).toMatch(/^sha256:[0-9a-f]{64}$/);
});
