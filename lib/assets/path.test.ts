import { expect, test } from "bun:test";
import type { AssetIdentity } from "./asset";
import { ASSET_MIME_EXTENSIONS, assetObjectPath, isAssetMime } from "./path";

const UNIT: AssetIdentity = {
  keyedOn: "unit",
  game: "BYAR",
  unitName: "armsolar",
  variant: "buildpic",
};

const MAP: AssetIdentity = {
  keyedOn: "map",
  mapName: 'Comet, Catcher (Remake) 1.8 "beta"',
  variant: "minimap",
};

test("a unit path names the game and the variant, and the hash is the leaf", () => {
  expect(assetObjectPath(UNIT, "0a1b2c3d", "image/webp")).toBe(
    "units/BYAR/buildpic/0a1b2c3d.webp",
  );
});

test("a render's angle becomes a path segment rather than a colon in a filename", () => {
  expect(assetObjectPath({ ...UNIT, variant: "render:270" }, "0a1b2c3d", "image/webp")).toBe(
    "units/BYAR/render/270/0a1b2c3d.webp",
  );
});

/**
 * A map name is the full canonical name the engine reports, and mappers put
 * commas, brackets and quotes in them. None of that can go in an object key or
 * a git tree, and the row already carries the name.
 */
test("a map path carries no map name at all", () => {
  const path = assetObjectPath(MAP, "0a1b2c3d", "image/webp");

  expect(path).toBe("maps/minimap/0a1b2c3d.webp");
  expect(path).not.toContain("Comet");
});

test("an overlay layer splits the same way a render does", () => {
  expect(assetObjectPath({ ...MAP, variant: "overlay:height" }, "0a1b", "image/webp")).toBe(
    "maps/overlay/height/0a1b.webp",
  );
});

test("the extension follows the declared type, not the caller", () => {
  expect(assetObjectPath(UNIT, "abc", "image/png")).toBe("units/BYAR/buildpic/abc.png");
});

test("two units in one game sharing a picture share one object", () => {
  const other: AssetIdentity = { ...UNIT, unitName: "cormoon" };

  // Deliberate. The unit name is not in the path, so identical encoded bytes
  // are stored once and cost one advanced operation rather than two.
  expect(assetObjectPath(other, "abc", "image/webp")).toBe(
    assetObjectPath(UNIT, "abc", "image/webp"),
  );
});

test("a type outside the allowlist has no path", () => {
  expect(assetObjectPath(UNIT, "abc", "image/gif")).toBeNull();
  expect(assetObjectPath(UNIT, "abc", "application/zip")).toBeNull();
  expect(isAssetMime("image/gif")).toBe(false);
});

test("only the listed types are types, including the ones every object inherits", () => {
  expect(Object.keys(ASSET_MIME_EXTENSIONS)).toEqual(["image/webp", "image/png"]);
  expect(isAssetMime("toString")).toBe(false);
  expect(isAssetMime("constructor")).toBe(false);
});

/**
 * The path is written into a public git repository by promotion, so traversal
 * here is not a bucket key problem, it is a commit.
 */
test("nothing that could climb out of the tree can be spelled", () => {
  expect(assetObjectPath({ ...UNIT, game: ".." }, "abc", "image/webp")).toBeNull();
  expect(assetObjectPath({ ...UNIT, game: "a/b" }, "abc", "image/webp")).toBeNull();
  expect(assetObjectPath({ ...UNIT, variant: "render:../../x" }, "abc", "image/webp")).toBeNull();
  expect(assetObjectPath(UNIT, "../secret", "image/webp")).toBeNull();
  expect(assetObjectPath(UNIT, ".hidden", "image/webp")).toBeNull();
  expect(assetObjectPath({ ...MAP, variant: "mini map" }, "abc", "image/webp")).toBeNull();
});
