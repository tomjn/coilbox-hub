import { expect, test } from "bun:test";
import { MAP_MINIMAP_VARIANT } from "@/lib/assets/asset";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import type { CardShape } from "./cardShapes";
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

const GALAXY_SHAPE: CardShape = {
  type: "galaxy",
  galaxy: {
    systems: [{ x: 0.5, y: 0.5, faction: 0, capital: true }],
    lanes: [],
    factionColors: ["#ffffff"],
  },
};

const RUN_SHAPE: CardShape = {
  type: "run",
  run: {
    steps: [{ x: 0, y: 0.5, type: "start" }],
    routes: [],
    columns: 1,
  },
};

const BLUEPRINT_SHAPE: CardShape = {
  type: "blueprint",
  layout: {
    width: 1,
    height: 1,
    squares: [],
    ordered: false,
  },
};

test("a conquest challenge whose shape rebuilt to a galaxy draws it", () => {
  expect(
    chooseItemCardArt({ kind: "challenge", map_name: null }, undefined, GALAXY_SHAPE),
  ).toEqual({ type: "art", shape: GALAXY_SHAPE });
});

test("a warpath challenge whose shape rebuilt to a run draws it", () => {
  expect(
    chooseItemCardArt({ kind: "challenge", map_name: null }, undefined, RUN_SHAPE),
  ).toEqual({ type: "art", shape: RUN_SHAPE });
});

test("a challenge with no shape, because its mode could not be rebuilt, keeps the plate", () => {
  expect(
    chooseItemCardArt({ kind: "challenge", map_name: null }, undefined, undefined),
  ).toEqual({ type: "plate" });
});

test("a blueprint whose shape rebuilt to a layout draws it", () => {
  expect(
    chooseItemCardArt({ kind: "blueprint", map_name: null }, undefined, BLUEPRINT_SHAPE),
  ).toEqual({ type: "art", shape: BLUEPRINT_SHAPE });
});

test("a blueprint with no shape, because it has no buildings or its container did not read, keeps the plate", () => {
  expect(
    chooseItemCardArt({ kind: "blueprint", map_name: null }, undefined, undefined),
  ).toEqual({ type: "plate" });
});

test("a galaxy or run shape on a row that is not a challenge is not drawn as art", () => {
  // Belt and braces: `cardShapes()` never actually produces a galaxy or a run
  // for anything but a challenge, but the plate is still the right fallback
  // if it ever did.
  expect(
    chooseItemCardArt({ kind: "scenario", map_name: null }, undefined, GALAXY_SHAPE),
  ).toEqual({ type: "plate" });
});

test("a blueprint shape on a row that is not a blueprint is not drawn as art", () => {
  // Belt and braces, the same reason as above: `cardShapes()` never produces
  // a blueprint shape for anything but a blueprint.
  expect(
    chooseItemCardArt({ kind: "scenario", map_name: null }, undefined, BLUEPRINT_SHAPE),
  ).toEqual({ type: "plate" });
});
