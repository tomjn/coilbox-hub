import { expect, test } from "bun:test";
import { ASSET_VOCABULARY_DIGEST } from "./vocabularyDigest";

/**
 * The digest is recorded by `bun run sync:vendor` rather than computed, because
 * a route runs from a bundle and cannot read the file's bytes back. Recorded
 * means it can go stale, and a stale one tells every client it is behind when
 * it is not, so this is what says it has not.
 */
test("the recorded digest is the sha256 of the vendored bytes", async () => {
  const bytes = await Bun.file("lib/assets/vendor/asset-vocabulary.json").bytes();
  const hex = Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)))
    .map((byte) => byte.toString(16).padStart(2, "0"))
    .join("");

  expect(ASSET_VOCABULARY_DIGEST).toBe(`sha256:${hex}`);
});
