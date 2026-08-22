import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { UnitCard } from "@/components/UnitCard";
import type { ResolvedAsset } from "@/lib/assets/resolve";

const PICTURE: ResolvedAsset = {
  from: "static",
  url: "https://example.test/armcom.webp",
  served: { keyedOn: "unit", game: "BA", unitName: "armcom", variant: "buildpic" },
  substituted: false,
  width: 128,
  height: 128,
};

test("a cell links to the unit's page and shows the name it has", () => {
  const html = renderToStaticMarkup(
    <UnitCard
      game="BA"
      unit={{ unit_name: "armcom", full_name: null }}
      picture={PICTURE}
    />,
  );
  expect(html).toContain('href="/games/BA/units/armcom"');
  expect(html).toContain("armcom");
});

test("a stored buildpic loads lazily below the first rows", () => {
  const html = renderToStaticMarkup(
    <UnitCard
      game="BA"
      unit={{ unit_name: "armcom", full_name: "Commander" }}
      picture={PICTURE}
    />,
  );
  expect(html).toContain('loading="lazy"');
  expect(html).not.toContain("Commander&");
});

test("a unit with no picture gets the drawing, not a broken frame", () => {
  const html = renderToStaticMarkup(
    <UnitCard
      game="BA"
      unit={{ unit_name: "armcom", full_name: "Commander" }}
      picture={{ from: "placeholder", keyedOn: "unit", name: "armcom", footprint: null }}
    />,
  );
  expect(html).not.toContain("<img");
});

test("the placeholder says nothing, since the cell prints the name itself", () => {
  const html = renderToStaticMarkup(
    <UnitCard
      game="BA"
      unit={{ unit_name: "arm_leftshoulder", full_name: null }}
      picture={{
        from: "placeholder",
        keyedOn: "unit",
        name: "arm_leftshoulder_nationwars_us",
        footprint: null,
      }}
    />,
  );
  // The caption would read the def key a second time under the label. It may
  // survive once, inside the drawing's aria-label, where nobody sees it twice.
  expect(html).not.toContain("No picture yet");
  expect(html.split("nationwars").length - 1).toBe(1);
});
