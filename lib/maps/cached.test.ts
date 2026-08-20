import { expect, test } from "bun:test";
import { MAP_MINIMAP_VARIANT } from "@/lib/assets/asset";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import { picturesFromEntries } from "@/lib/maps/cached";

const COMET = "Comet Catcher Remake 1.8";

const PICTURE: ResolvedAsset = {
  from: "static",
  url: "https://example.test/comet.webp",
  served: { keyedOn: "map", mapName: COMET, variant: MAP_MINIMAP_VARIANT },
  substituted: false,
  width: 512,
  height: 512,
};

test("a lookup survives the trip out as entries and back", () => {
  const pictures = picturesFromEntries([...new Map([[COMET, PICTURE]])]);
  expect(pictures.get(COMET)).toEqual(PICTURE);
});

test("a name the page holds no picture for is still absent", () => {
  expect(picturesFromEntries([]).get(COMET)).toBeUndefined();
});
