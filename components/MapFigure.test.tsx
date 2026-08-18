import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { MapFigure } from "@/components/MapFigure";
import { MAP_MINIMAP_VARIANT } from "@/lib/assets/asset";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import type { MapPoint } from "@/lib/maps/facts";
import { mapSquares } from "@/lib/maps/labels";

/**
 * The figure is markup rather than data, so it is proved by rendering it. What
 * goes wrong here goes wrong in the browser: a placeholder drawn square for a
 * map that is not, and a marker placed by dividing by the wrong edge.
 */

const COMET = "Comet Catcher Remake 1.8";

/** A 12 x 20, which is the shape a square hides every mistake on. */
const MAP = { width_elmos: 6144, height_elmos: 10240 };

const NO_POINTS = { start: [], metal: [], geo: [] };

/** A point as `public.map_point` stores one. `y` is null on almost every start
 *  position, since the engine resolves a spawn height from the terrain. */
function point(x: number, z: number): MapPoint {
  return { x, z, y: null, meta: null };
}

const placeholder: ResolvedAsset = {
  from: "placeholder",
  name: COMET,
  keyedOn: "map",
  footprint: mapSquares(MAP.width_elmos, MAP.height_elmos),
};

const stored: ResolvedAsset = {
  from: "static",
  url: "https://example.test/maps/minimap/comet.webp",
  served: { keyedOn: "map", mapName: COMET, variant: MAP_MINIMAP_VARIANT },
  substituted: false,
  width: 512,
  height: 512,
};

/** Every `left` and `top` the markup places a marker at, as numbers. */
function positions(html: string): { left: number; top: number }[] {
  return [...html.matchAll(/left:([\d.]+)%;top:([\d.]+)%/g)].map((match) => ({
    left: Number(match[1]),
    top: Number(match[2]),
  }));
}

test("a map with no stored minimap is drawn at the catalog's shape rather than a square", () => {
  const html = renderToStaticMarkup(
    <MapFigure name={COMET} map={MAP} points={NO_POINTS} picture={placeholder} />,
  );

  // 100 by 100 is the box `placeholderBox` draws when it is told nothing, and
  // is what this figure would show if it passed the catalog's size on wrongly
  // or not at all.
  expect(html).toContain('viewBox="0 0 60 100"');
  expect(html).not.toContain('viewBox="0 0 100 100"');
});

/**
 * The elmo to percentage division, seen through the markup that carries it. A
 * start position near the far corner of a 12 x 20 sits near the far corner of
 * the figure, and dividing both edges by the width would put it a third of the
 * way past the bottom.
 */
test("start markers land inside the figure on a map that is not square", () => {
  const start = [point(512, 512), point(5632, 9728)];
  const html = renderToStaticMarkup(
    <MapFigure name={COMET} map={MAP} points={{ ...NO_POINTS, start }} picture={stored} />,
  );

  const placed = positions(html);
  expect(placed).toHaveLength(2);
  for (const { left, top } of placed) {
    expect(left).toBeGreaterThan(0);
    expect(left).toBeLessThan(100);
    expect(top).toBeGreaterThan(0);
    expect(top).toBeLessThan(100);
  }

  expect(placed[1].left).toBeCloseTo(91.7, 1);
  expect(placed[1].top).toBeCloseTo(95, 1);
});

test("the picture fills the frame the catalog's size sets", () => {
  const html = renderToStaticMarkup(
    <MapFigure name={COMET} map={MAP} points={NO_POINTS} picture={stored} />,
  );

  expect(html).toContain("aspect-ratio:6144 / 10240");
  expect(html).toContain("object-fill");
});

/** Real checkboxes with real labels, so the layers work with no script running
 *  and a screen reader is told what the control is. */
test("the layers are toggled by labelled checkboxes", () => {
  const html = renderToStaticMarkup(
    <MapFigure
      name={COMET}
      map={MAP}
      points={{ start: [], metal: [point(1024, 2048)], geo: [point(2048, 4096)] }}
      picture={stored}
    />,
  );

  expect(html).toContain('<input id="map-metal-spots" type="checkbox"');
  expect(html).toContain('for="map-metal-spots"');
  expect(html).toContain("Metal spots");
  expect(html).toContain('for="map-geo-vents"');
  expect(html).toContain("Geo vents");
});

/** A toggle for a layer with nothing in it is a control that does nothing. */
test("a map with no geo vents offers no geo toggle", () => {
  const html = renderToStaticMarkup(
    <MapFigure
      name={COMET}
      map={MAP}
      points={{ start: [], metal: [point(1024, 2048)], geo: [] }}
      picture={stored}
    />,
  );

  expect(html).toContain("Metal spots");
  expect(html).not.toContain("Geo vents");
});

/** Dots on a picture say nothing to a screen reader, and the facts beside it
 *  already say how many players the map is for. */
test("the marker layers are hidden from a screen reader", () => {
  const html = renderToStaticMarkup(
    <MapFigure
      name={COMET}
      map={MAP}
      points={{ ...NO_POINTS, start: [point(512, 512)] }}
      picture={stored}
    />,
  );

  expect(html).toContain('<ul aria-hidden="true"');
});
