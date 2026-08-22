import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { StatTable } from "@/components/StatTable";

/**
 * A weapons summary draws as a table, not a JSON blob (#261). Everything that
 * is not a list of records keeps the one-line print it had.
 */

test("a weapons list becomes a table with a column per field", () => {
  const html = renderToStaticMarkup(
    <StatTable
      stats={{
        health: 4500,
        weapons: [
          { range: 300, damage: 75, reload: 0.4, projectile: "BeamLaser" },
          { range: 250, damage: 99999, reload: 1, projectile: "DGun" },
        ],
      }}
    />,
  );

  expect(html).toContain("<table");
  expect(html).toContain("<th");
  expect(html).toContain("BeamLaser");
  expect(html).not.toContain("&quot;range&quot;");
  expect(html).not.toContain('{"range"');
});

test("a weapon missing a column the others have leaves its cell empty", () => {
  const html = renderToStaticMarkup(
    <StatTable stats={{ weapons: [{ range: 300 }, { range: 260, damage: 125 }] }} />,
  );

  expect(html.split("<td").length - 1).toBe(4);
  // The empty cell prints as the absent mark, not zero.
  expect(html).toContain(">-</td>");
});

test("scalar stats still print on their own line", () => {
  const html = renderToStaticMarkup(<StatTable stats={{ health: 4500 }} />);

  expect(html).not.toContain("<table");
  expect(html).toContain("4500");
});
