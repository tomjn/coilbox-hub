import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { TreeBlock } from "@/components/GameTree";
import type { ResolvedAsset } from "@/lib/assets/resolve";
import { buildTree, type TreeNode } from "@/lib/games/tree";

/**
 * The rendering walk (#257, #266). The grouping in `buildTree` terminates on
 * its own; the cost was the page drawing once per path through the graph. A
 * unit now unfolds at most once per request, wherever its first occurrence
 * lands, and later mentions are edge nodes that do not recurse. The start
 * units render already open, with buildpics on every row (#276).
 */

const UNITS = [
  { unit_name: "armcom", full_name: "Commander", build_options: ["armveh", "armsolar"] },
  { unit_name: "armveh", full_name: "Vehicle Plant", build_options: ["armcv"] },
  { unit_name: "armcv", full_name: "Construction Vehicle", build_options: ["armveh"] },
  { unit_name: "armsolar", full_name: "Solar Collector", build_options: [] },
];

const PICTURE: ResolvedAsset = {
  from: "static",
  url: "https://example.test/buildpic.webp",
  served: { keyedOn: "unit", game: "BA", unitName: "x", variant: "buildpic" },
  substituted: false,
  width: 64,
  height: 64,
};

function block() {
  const tree = buildTree(UNITS, ["armcom"]);
  const byName = new Map<string, TreeNode>();
  const pictures = new Map<string, ResolvedAsset>();
  for (const faction of tree.factions) {
    for (const node of faction.units) {
      byName.set(node.name, node);
      pictures.set(node.name, PICTURE);
    }
  }
  return renderToStaticMarkup(
    <TreeBlock
      game="BA"
      heading="Arm"
      note="4 units"
      roots={tree.factions[0].units.filter((unit) => unit.name === "armcom")}
      byName={byName}
      pictures={pictures}
      expanded={new Set()}
    />,
  );
}

test("every branch renders expanded", () => {
  const html = block();

  expect(html.split("<details").length - 1).toBe(3);
  expect(html.split("<details open").length - 1).toBe(3);
});

test("leaf ends lead their level, ahead of the builders", () => {
  const html = block();

  // The commander lists the vehicle plant first in its build options, but
  // dead ends draw across before the vertical spine starts.
  expect(html.indexOf("Solar Collector")).toBeLessThan(html.indexOf("Vehicle Plant"));
});

test("each expander carries a plus that turns on open", () => {
  const html = block();

  expect(html.match(/group-open:rotate-45/g)?.length).toBe(3);
});

test("every row carries a buildpic", () => {
  const html = block();

  // Five rows: four units plus the vehicle plant again as an edge node.
  expect(html.split("<img").length - 1).toBe(5);
});

test("the loop closes as an edge node, not another lap", () => {
  const html = block();

  // The vehicle plant opens once under the commander; the construction
  // vehicle's own build option is a link that does not unfold.
  expect(html.split("Vehicle Plant").length - 1).toBe(2);
  expect(html.split("<details").length - 1).toBe(3);
});

test("every unit is reachable from the start unit", () => {
  const html = block();

  expect(html).toContain("Solar Collector");
  expect(html).toContain("Construction Vehicle");
  expect(html).toContain('href="/games/BA/units/armveh"');
});
