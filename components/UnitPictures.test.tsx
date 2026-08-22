import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { UnitPortrait, UnitRenderFigure } from "@/components/UnitPictures";
import type { ResolvedAsset } from "@/lib/assets/resolve";

/**
 * The unit's two pictures draw apart (#268): the buildpic as the hero
 * portrait beside the name, the top down render as its own section.
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

test("the portrait shows the buildpic and says so", () => {
  const html = renderToStaticMarkup(<UnitPortrait label="Commander" asset={BUILDPICTURE} />);

  expect(html).toContain('alt="Picture of Commander"');
  expect(html).toContain("Buildpic");
});

test("the render section draws a stored top down render", () => {
  const html = renderToStaticMarkup(<UnitRenderFigure label="Commander" render={RENDER} />);

  expect(html).toContain('alt="Top down render of Commander"');
  expect(html).toContain("Top down render");
});

test("a substituted render is not drawn twice", () => {
  // When no buildpic exists the portrait already carries whatever the render
  // resolution found; the section would repeat it.
  const html = renderToStaticMarkup(
    <UnitRenderFigure
      label="Commander"
      render={{ ...BUILDPICTURE, substituted: true }}
    />,
  );

  expect(html).not.toContain("<img");
});

test("nothing stored draws the placeholder in the portrait only", () => {
  const nothing: ResolvedAsset = { from: "placeholder", keyedOn: "unit", name: "armcom", footprint: null };
  const portrait = renderToStaticMarkup(<UnitPortrait label="Commander" asset={nothing} />);
  const section = renderToStaticMarkup(<UnitRenderFigure label="Commander" render={nothing} />);

  expect(portrait).not.toContain("<img");
  expect(section).toBe("");
});
