import { expect, test } from "bun:test";
import { MAP_MINIMAP_VARIANT } from "@/lib/assets/asset";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import { chooseItemCardArt, itemNamesItsMap } from "./itemCardArt";

const PICTURE: ResolvedAsset = {
  from: "static",
  url: "https://example.test/comet.webp",
  served: { keyedOn: "map", mapName: "Comet Catcher", variant: MAP_MINIMAP_VARIANT },
  substituted: false,
  width: 512,
  height: 512,
};

const PLACEHOLDER: ResolvedAsset = {
  from: "placeholder",
  name: "Comet Catcher",
  keyedOn: "map",
  footprint: null,
};

test("itemNamesItsMap is true only for a scenario or a preset that names one", () => {
  expect(itemNamesItsMap({ kind: "scenario", map_name: "Comet Catcher" })).toBe(true);
  expect(itemNamesItsMap({ kind: "preset", map_name: "Comet Catcher" })).toBe(true);
  expect(itemNamesItsMap({ kind: "setup-pack", map_name: "Comet Catcher" })).toBe(false);
  expect(itemNamesItsMap({ kind: "blueprint", map_name: "Comet Catcher" })).toBe(false);
  expect(itemNamesItsMap({ kind: "challenge", map_name: "Comet Catcher" })).toBe(false);
  expect(itemNamesItsMap({ kind: "scenario", map_name: null })).toBe(false);
});

test("a scenario with a map and a resolved picture gets the map", () => {
  expect(chooseItemCardArt({ kind: "scenario", map_name: "Comet Catcher" }, PICTURE)).toEqual({
    type: "map",
    picture: PICTURE,
  });
});

test("a preset with a map and a resolved picture gets the map", () => {
  expect(chooseItemCardArt({ kind: "preset", map_name: "Comet Catcher" }, PICTURE)).toEqual({
    type: "map",
    picture: PICTURE,
  });
});

test("a map resolved to the placeholder still counts as the map's own art, not the plate", () => {
  expect(chooseItemCardArt({ kind: "scenario", map_name: "Comet Catcher" }, PLACEHOLDER)).toEqual({
    type: "map",
    picture: PLACEHOLDER,
  });
});

test("a scenario or preset with no map_name gets the plate", () => {
  expect(chooseItemCardArt({ kind: "scenario", map_name: null }, PICTURE)).toEqual({
    type: "plate",
  });
  expect(chooseItemCardArt({ kind: "preset", map_name: null }, PICTURE)).toEqual({
    type: "plate",
  });
});

test("a setup pack never draws its map_name, even with a picture resolved for it", () => {
  expect(chooseItemCardArt({ kind: "setup-pack", map_name: "Comet Catcher" }, PICTURE)).toEqual({
    type: "plate",
  });
});

test("a blueprint or challenge gets the plate: neither names a map on the row", () => {
  expect(chooseItemCardArt({ kind: "blueprint", map_name: null }, undefined)).toEqual({
    type: "plate",
  });
  expect(chooseItemCardArt({ kind: "challenge", map_name: null }, undefined)).toEqual({
    type: "plate",
  });
});

test("a row nothing was looked up for falls back to the plate rather than throwing", () => {
  expect(chooseItemCardArt({ kind: "scenario", map_name: "Comet Catcher" }, undefined)).toEqual({
    type: "plate",
  });
});
