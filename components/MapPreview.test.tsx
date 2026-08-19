import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { MapPreview } from "@/components/MapPreview";
import type { MapPreview as MapPreviewData } from "@/lib/maps/preview";

/**
 * That the preview stays out of the way until it is asked for (#194).
 *
 * ## What these prove and what they do not
 *
 * A `bun test` run has no bundler in it, so nothing here can watch a chunk being
 * fetched. What it can do is check the two things that decide whether a chunk
 * gets split at all: that the first render is a button and no canvas, and that
 * `MapPreview.tsx` has no static path to three.js. The source check is the one
 * that would catch the regression worth catching, which is somebody adding
 * `import { drawTerrain } from "./mapTerrain"` at the top of the file and
 * putting half a megabyte on every map page without anything looking different.
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
    sky: null,
    fog: null,
    sunDirection: null,
    sunColour: null,
  },
};

test("the shut preview is a button and nothing else", () => {
  const html = renderToStaticMarkup(<MapPreview name={COMET} preview={PREVIEW} />);

  expect(html).toContain("See the terrain");
  expect(html).not.toContain("<canvas");
  // No frame either. A box waiting for a scene nobody has asked for would push
  // the rest of the page down for nothing.
  expect(html).not.toContain("aspect-ratio");
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
