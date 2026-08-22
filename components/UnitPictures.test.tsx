import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { UnitPictures } from "@/components/UnitPictures";
import type { ResolvedAsset } from "@/lib/assets/resolve";

/**
 * Which pictures a unit's page draws (#259): both when the hub holds both,
 * one when the render is the buildpic by substitution, and the drawing when
 * the hub holds nothing.
 */

const RENDER: ResolvedAsset = {
  from: "static",
  url: "https://example.test/armcom-render.webp",
  served: { keyedOn: "unit", game: "BA", unitName: "armcom", variant: "render:top" },
  substituted: false,
  width: 256,
  height: 256,
};

const BUILDPICTURE: ResolvedAsset = {
  from: "blob",
  url: "https://example.test/armcom-buildpic.webp",
  served: { keyedOn: "unit", game: "BA", unitName: "armcom", variant: "buildpic" },
  substituted: false,
  width: 128,
  height: 128,
};

test("both pictures show side by side when the hub holds both", () => {
  const html = renderToStaticMarkup(
    <UnitPictures label="Commander" render={RENDER} buildpic={BUILDPICTURE} />,
  );

  expect(html).toContain('alt="Top down render of Commander"');
  expect(html).toContain('alt="Buildpic of Commander"');
  expect(html).toContain("Top down render");
  expect(html).toContain("Buildpic");
});

test("a missing render leaves one picture, not the buildpic twice", () => {
  const html = renderToStaticMarkup(
    <UnitPictures
      label="Commander"
      render={{ ...BUILDPICTURE, served: { ...BUILDPICTURE.served, variant: "render:top" }, substituted: true }}
      buildpic={BUILDPICTURE}
    />,
  );

  expect(html.split("<img").length - 1).toBe(1);
});

test("nothing stored draws the placeholder and no captions", () => {
  const nothing: ResolvedAsset = { from: "placeholder", keyedOn: "unit", name: "armcom", footprint: null };
  const html = renderToStaticMarkup(
    <UnitPictures label="Commander" render={nothing} buildpic={{ ...nothing }} />,
  );

  expect(html).not.toContain("<img");
  expect(html).not.toContain("Buildpic</figcaption>");
});
