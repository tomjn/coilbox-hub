import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { ItemCardArt } from "@/components/ItemCardArt";
import { MAP_MINIMAP_VARIANT } from "@/lib/assets/asset";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import type { CardShape } from "@/lib/gallery/cardShapes";

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

const GALAXY_SHAPE: CardShape = {
  type: "galaxy",
  galaxy: {
    systems: [
      { x: 0.2, y: 0.2, faction: 0, capital: true },
      { x: 0.5, y: 0.5, faction: null, capital: false },
      { x: 0.8, y: 0.3, faction: 1, capital: false },
    ],
    lanes: [
      [0, 1],
      [1, 2],
    ],
    factionColors: ["#ffffff", "#ff0000"],
  },
};

const RUN_SHAPE: CardShape = {
  type: "run",
  run: {
    steps: [
      { x: 0, y: 0.5, type: "start" },
      { x: 0.5, y: 0.3, type: "battle" },
      { x: 0.5, y: 0.7, type: "shop" },
      { x: 1, y: 0.5, type: "boss" },
    ],
    routes: [
      [0, 1],
      [0, 2],
      [1, 3],
      [2, 3],
    ],
    columns: 3,
  },
};

test("a conquest challenge with a galaxy shape draws an SVG with one circle per system, aria-hidden", () => {
  const html = renderToStaticMarkup(
    <ItemCardArt
      item={{ kind: "challenge", mode: "conquest", map_name: null }}
      picture={undefined}
      shape={GALAXY_SHAPE}
    />,
  );

  expect(html).toContain("aria-hidden");
  expect(html.match(/<circle/g)).toHaveLength(GALAXY_SHAPE.galaxy.systems.length);
});

test("a warpath challenge with a run shape draws an SVG with one circle per step, aria-hidden", () => {
  const html = renderToStaticMarkup(
    <ItemCardArt
      item={{ kind: "challenge", mode: "warpath", map_name: null }}
      picture={undefined}
      shape={RUN_SHAPE}
    />,
  );

  expect(html).toContain("aria-hidden");
  expect(html.match(/<circle/g)).toHaveLength(RUN_SHAPE.run.steps.length);
  // No legend on a card: the seven warpath node type names stay on the item
  // page (issue #309).
  expect(html).not.toContain("Battle");
  expect(html).not.toContain("Boss");
});

test("a challenge with no shape keeps the kind plate rather than an empty box", () => {
  const html = renderToStaticMarkup(
    <ItemCardArt
      item={{ kind: "challenge", mode: "conquest", map_name: null }}
      picture={undefined}
      shape={undefined}
    />,
  );

  expect(html).toContain("aria-hidden");
  // The plate's own glyph, not the galaxy: `KindIcon` draws a few circles of
  // its own (24 unit viewBox), which is not the 100 unit galaxy viewBox.
  expect(html).toContain('viewBox="0 0 24 24"');
  expect(html).not.toContain('viewBox="0 0 100 100"');
});

const BLUEPRINT_SHAPE: CardShape = {
  type: "blueprint",
  layout: {
    width: 8,
    height: 3,
    ordered: false,
    squares: [
      { def: "armsolar", sized: true, x: 0, y: 0, width: 1, height: 1 },
      { def: "armsolar", sized: true, x: 2, y: 0, width: 1, height: 1 },
      { def: "armlab", sized: true, x: 4, y: 0, width: 3, height: 3 },
    ],
  },
};

test("a blueprint with a layout shape draws one rect per building, aria-hidden, with no pictures", () => {
  const html = renderToStaticMarkup(
    <ItemCardArt
      item={{ kind: "blueprint", mode: null, map_name: null }}
      picture={undefined}
      shape={BLUEPRINT_SHAPE}
    />,
  );

  expect(html).toContain("aria-hidden");
  expect(html.match(/<rect/g)).toHaveLength(BLUEPRINT_SHAPE.layout.squares.length);
  // No per-building picture lookups on a card: `lib/gallery/itemCardArt.ts`
  // passes no `units` map, so `BlueprintLayoutArt` draws every square plain.
  expect(html).not.toContain("<img");
});
