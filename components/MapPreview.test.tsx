import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { MapPreview } from "@/components/MapPreview";
import type { MapPreview as MapPreviewData } from "@/lib/maps/preview";

/**
 * That the page is the flat figure until the terrain has drawn, and that the
 * terrain still costs nothing to anybody who never sees it (#194).
 *
 * ## What these prove and what they do not
 *
 * A `bun test` run has no bundler in it, so nothing here can watch a chunk being
 * fetched. What it can do is check the two things that decide whether a chunk
 * gets split at all: that the served markup is the minimap and no canvas, and
 * that `MapPreview.tsx` has no static path to three.js. The source check is the
 * one that would catch the regression worth catching, which is somebody adding
 * `import { drawTerrain } from "./mapTerrain"` at the top of the file and putting
 * half a megabyte on every map page without anything looking different.
 *
 * It does not prove Next actually emitted a separate chunk. `bun run build`
 * does: three lands in a chunk of its own that the route's client manifest does
 * not list, which is recorded on the pull request rather than asserted here.
 */

const COMET = "Comet Catcher Remake 1.8";

const PREVIEW: MapPreviewData = {
  heightUrl: "https://example.test/maps/overlay/height/ghi.webp",
  range: { min: -120.5, max: 890 },
  textureUrl: "https://example.test/maps/minimap/def.webp",
  widthElmos: 6144,
  heightElmos: 10240,
  voidWater: false,
  appearance: {
    water: null,
    waterAlpha: null,
    sunDirection: null,
    sunColour: null,
  },
  points: {
    start: [{ x: 512, z: 9728 }],
    metal: [{ x: 1024, z: 2048 }],
    geo: [{ x: 3072, z: 5120 }],
  },
};

/** Stands in for `components/MapFigure.tsx`, which the page renders on the
 *  server and hands in as a child. */
const FIGURE = <p>the flat minimap</p>;

test("the served markup is the flat figure and no scene", () => {
  const html = renderToStaticMarkup(
    <MapPreview name={COMET} preview={PREVIEW}>
      {FIGURE}
    </MapPreview>,
  );

  expect(html).toContain("the flat minimap");
  expect(html).not.toContain("<canvas");
});

/** Hidden rather than absent, so a browser that draws the terrain and one that
 *  cannot are served the same HTML. The frame is measured by the
 *  `ResizeObserver` in `mapTerrain.ts` once it is shown, which is what lets it
 *  be mounted with no size at all. */
test("the scene's frame is in the markup, shut", () => {
  const html = renderToStaticMarkup(
    <MapPreview name={COMET} preview={PREVIEW}>
      {FIGURE}
    </MapPreview>,
  );

  expect(html).toContain('<figure class="hidden"');
  expect(html).toContain("h-[512px]");
});

/** The same layers the flat figure puts behind a checkbox, and only the layers
 *  this map has any points for. */
test("a layer with no points has no chip", () => {
  const html = renderToStaticMarkup(
    <MapPreview
      name={COMET}
      preview={{ ...PREVIEW, points: { start: [], metal: [], geo: [] } }}
    >
      {FIGURE}
    </MapPreview>,
  );

  expect(html).not.toContain("Metal spots");
  expect(html).not.toContain("Geo vents");
  expect(html).not.toContain("Start positions");
});

test("the layers this map does have are offered", () => {
  const html = renderToStaticMarkup(
    <MapPreview name={COMET} preview={PREVIEW}>
      {FIGURE}
    </MapPreview>,
  );

  expect(html).toContain("Metal spots");
  expect(html).toContain("Geo vents");
  expect(html).toContain("Start positions");
});

/** The whole arrangement rests on this one line staying a call rather than a
 *  declaration. A static import would still typecheck, still render and still
 *  work, and would quietly put three.js on every map page. */
test("nothing in the component reaches three.js except through a dynamic import", async () => {
  const source = await Bun.file(
    new URL("./MapPreview.tsx", import.meta.url).pathname,
  ).text();

  expect(source).toContain('import("./mapTerrain")');
  // `import type` is erased at compile time, so it is the one static mention
  // allowed. Anything else is a real edge in the module graph.
  for (const line of source.split("\n")) {
    const isStaticImport = /^\s*import\s+(?!type\b)/.test(line);
    if (!isStaticImport) continue;
    expect(line).not.toContain("three");
    expect(line).not.toContain("mapTerrain");
  }
});
