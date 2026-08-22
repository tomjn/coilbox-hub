import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { TreeBlock } from "@/components/GameTree";
import { buildTree, type TreeNode } from "@/lib/games/tree";

/**
 * The rendering walk (#257). The grouping in `buildTree` terminates on its own;
 * the cost was the page unfolding every path, and a graph with a build loop has
 * paths that only a depth bound ends - by drawing the same units lap after lap.
 */

const UNITS = [
  { unit_name: "armalab", full_name: "Kbot Lab", build_options: ["armack", "armsolar"] },
  { unit_name: "armack", full_name: "Construction Kbot", build_options: ["armalab"] },
  { unit_name: "armsolar", full_name: "Solar Collector", build_options: [] },
];

function block() {
  const tree = buildTree(UNITS, ["armalab"]);
  const byName = new Map<string, TreeNode>();
  for (const faction of tree.factions) {
    for (const node of faction.units) byName.set(node.name, node);
  }
  return (
    <TreeBlock
      game="BA"
      heading="Arm"
      note="3 units"
      nodes={tree.factions[0].units}
      byName={byName}
      q={null}
    />
  );
}

test("a build loop is drawn a bounded number of times, not once per lap", () => {
  const html = renderToStaticMarkup(block());

  // Once as the faction's root and once as the link that closes the loop
  // under the construction kbot. Without the ancestor check this walk laps
  // the lab/kbot loop until MAX_DEPTH and the count grows with it.
  expect(html.split("Kbot Lab").length - 1).toBe(2);
});

test("every unit still appears, and what is built stays listed", () => {
  const html = renderToStaticMarkup(block());

  expect(html).toContain("Solar Collector");
  expect(html).toContain("Construction Kbot");
  expect(html).toContain("builds 2");
});
