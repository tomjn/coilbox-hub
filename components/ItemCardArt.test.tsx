import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { ItemCardArt } from "@/components/ItemCardArt";
import { MAP_MINIMAP_VARIANT } from "@/lib/assets/asset";
import type { ResolvedAsset } from "@/lib/assets/resolve";

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

test("a scenario with a resolved picture draws the map, with alt text naming it", () => {
  const html = renderToStaticMarkup(
    <ItemCardArt item={{ kind: "scenario", mode: null, map_name: "Comet Catcher" }} picture={PICTURE} />,
  );

  expect(html).toContain('alt="Minimap of Comet Catcher"');
  expect(html).toContain(PICTURE.url);
});

test("a preset whose map has no stored picture draws the labelled placeholder, not an empty box", () => {
  const html = renderToStaticMarkup(
    <ItemCardArt item={{ kind: "preset", mode: null, map_name: "Comet Catcher" }} picture={PLACEHOLDER} />,
  );

  expect(html).toContain("No picture of Comet Catcher yet");
});

test("a setup pack never draws its map_name, even with a picture resolved for it", () => {
  const html = renderToStaticMarkup(
    <ItemCardArt item={{ kind: "setup-pack", mode: null, map_name: "Comet Catcher" }} picture={PICTURE} />,
  );

  expect(html).not.toContain(PICTURE.url);
  expect(html).toContain("aria-hidden");
});

test("a blueprint with no map at all gets a decorative kind plate rather than an empty slot", () => {
  const html = renderToStaticMarkup(
    <ItemCardArt item={{ kind: "blueprint", mode: null, map_name: null }} picture={undefined} />,
  );

  expect(html).toContain("aria-hidden");
});

test("a scenario naming a map nothing was looked up for still falls back to the plate", () => {
  const html = renderToStaticMarkup(
    <ItemCardArt item={{ kind: "scenario", mode: null, map_name: "Comet Catcher" }} picture={undefined} />,
  );

  expect(html).toContain("aria-hidden");
  expect(html).not.toContain(PICTURE.url);
});
