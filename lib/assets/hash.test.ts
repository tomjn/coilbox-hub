import { expect, test } from "bun:test";
import { encodedHash } from "./hash";
import { assetObjectPath } from "./path";

function bytes(text: string): ArrayBuffer {
  return new TextEncoder().encode(text).buffer as ArrayBuffer;
}

/**
 * Pinned against the published SHA-256 vectors rather than against itself, so
 * this fails if the algorithm or the spelling ever moves. It has to keep
 * agreeing with what coilbox produces, and coilbox is a different codebase in a
 * different language, so "whatever this function returns" is not a test.
 */
test("the digest is SHA-256 as 64 lowercase hex characters", async () => {
  expect(await encodedHash(bytes(""))).toBe(
    "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
  );
  expect(await encodedHash(bytes("abc"))).toBe(
    "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad",
  );
});

test("different bytes get a different name and the same bytes get the same one", async () => {
  const one = await encodedHash(bytes("a picture"));

  expect(await encodedHash(bytes("a picture"))).toBe(one);
  expect(await encodedHash(bytes("a picture."))).not.toBe(one);
});

/**
 * The reason the spelling matters. A digest with a slash, a plus or padding in
 * it would come back from `assetObjectPath` as null and refuse every upload,
 * which is how base64 would fail. Hex cannot.
 */
test("the digest is always spellable as a path segment", async () => {
  const hash = await encodedHash(bytes("a minimap"));

  const map = { keyedOn: "map", mapName: "Tangerine 1.1", variant: "minimap" } as const;

  expect(assetObjectPath(map, hash, "image/webp")).toBe(`maps/minimap/${hash}.webp`);
});
