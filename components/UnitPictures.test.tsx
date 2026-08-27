import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { UnitPortrait, UnitRenders } from "@/components/UnitPictures";
import type { ResolvedAsset } from "@/lib/assets/resolve";

/**
 * The unit's pictures draw apart (#268): the buildpic as the hero portrait
 * beside the name, every stored render angle in a section of its own.
 */

function render(angle: string): ResolvedAsset {
  return {
    from: "static",
    url: `https://example.test/armcom-${angle}.webp`,
    served: { keyedOn: "unit", game: "BA", unitName: "armcom", variant: `render:${angle}` },
    substituted: false,
    width: 256,
    height: 256,
  };
}

const RENDER = render("top");

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
  const html = renderToStaticMarkup(
    <UnitRenders label="Commander" renders={[{ angle: "top", asset: RENDER }]} />,
  );

  expect(html).toContain('alt="Top down render of Commander"');
  expect(html).toContain("Top down");
});

/**
 * Coilbox draws four angles and used to be able to send all four to a page that
 * only ever asked for one, so this is what proves the other three arrive.
 */
test("every angle the hub holds gets its own figure", () => {
  const html = renderToStaticMarkup(
    <UnitRenders
      label="Commander"
      renders={["top", "front", "side", "angled"].map((angle) => ({
        angle,
        asset: render(angle),
      }))}
    />,
  );

  expect(html.match(/<img/g)).toHaveLength(4);
  for (const angle of ["front", "side", "angled"]) {
    expect(html).toContain(`https://example.test/armcom-${angle}.webp`);
  }
  expect(html).toContain('alt="Front render of Commander"');
  expect(html).toContain('alt="Side render of Commander"');
  expect(html).toContain('alt="Angled render of Commander"');
});

test("an angle the hub does not hold is left out rather than drawn empty", () => {
  const html = renderToStaticMarkup(
    <UnitRenders
      label="Commander"
      renders={[
        { angle: "top", asset: RENDER },
        {
          angle: "front",
          asset: { from: "placeholder", keyedOn: "unit", name: "armcom", footprint: null },
        },
      ]}
    />,
  );

  expect(html.match(/<img/g)).toHaveLength(1);
  expect(html).not.toContain("Front");
});

test("a substituted render is not drawn twice", () => {
  // When no buildpic exists the portrait already carries whatever the render
  // resolution found, so the section would repeat it.
  const html = renderToStaticMarkup(
    <UnitRenders
      label="Commander"
      renders={[{ angle: "top", asset: { ...BUILDPICTURE, substituted: true } }]}
    />,
  );

  expect(html).not.toContain("<img");
});

test("nothing stored draws the placeholder in the portrait only", () => {
  const nothing: ResolvedAsset = { from: "placeholder", keyedOn: "unit", name: "armcom", footprint: null };
  const portrait = renderToStaticMarkup(<UnitPortrait label="Commander" asset={nothing} />);
  const section = renderToStaticMarkup(
    <UnitRenders label="Commander" renders={[{ angle: "top", asset: nothing }]} />,
  );

  expect(portrait).not.toContain("<img");
  expect(section).toBe("");
});
