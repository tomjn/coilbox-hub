import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { TreeBlock } from "@/components/GameTree";
import { buildTree, type TreeNode } from "@/lib/games/tree";

/**
 * The rendering walk (#257, #266). The grouping in `buildTree` terminates on
 * its own; the cost was the page drawing once per path through the graph. A
 * unit now unfolds at most once per request, wherever its first occurrence
 * lands, and later mentions are edge nodes that do not recurse.
 */

const UNITS = [
  { unit_name: "armcom", full_name: "Commander", build_options: ["armveh", "armsolar"] },
  { unit_name: "armveh", full_name: "Vehicle Plant", build_options: ["armcv"] },
  { unit_name: "armcv", full_name: "Construction Vehicle", build_options: ["armveh"] },
  { unit_name: "armsolar", full_name: "Solar Collector", build_options: [] },
];

function block() {
  const tree = buildTree(UNITS, ["armcom"]);
  const byName = new Map<string, TreeNode>();
  for (const faction of tree.factions) {
    for (const node of faction.units) byName.set(node.name, node);
  }
  return renderToStaticMarkup(
    <TreeBlock
      game="BA"
      heading="Arm"
      note="4 units"
      roots={tree.factions[0].units.filter((unit) => unit.name === "armcom")}
      byName={byName}
      expanded={new Set()}
    />,
  );
}

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

test("a chain deeper than the old depth bound renders to its end", () => {
  // Expansion-once terminates on its own, so there is no MAX_DEPTH left to
  // truncate real graphs: Balanced Annihilation reaches depth 19.
  const LENGTH = 20;
  const chain = Array.from({ length: LENGTH }, (_, index) => ({
    unit_name: `chain${index}`,
    full_name: `Chain ${index}`,
    build_options: index < LENGTH - 1 ? [`chain${index + 1}`] : [],
  }));
  const tree = buildTree(chain, ["chain0"]);
  const byName = new Map<string, TreeNode>();
  for (const faction of tree.factions) {
    for (const node of faction.units) byName.set(node.name, node);
  }
  const html = renderToStaticMarkup(
    <TreeBlock
      game="BA"
      heading="Arm"
      roots={tree.factions[0].units.filter((unit) => unit.name === "chain0")}
      byName={byName}
      expanded={new Set()}
    />,
  );

  expect(html).toContain("Chain 19");
});
