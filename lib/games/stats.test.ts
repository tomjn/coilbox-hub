import { expect, test } from "bun:test";
import { formatStatValue, statLabel, statRows, tabularColumns, tabularStatRows } from "./stats";

/**
 * How a stats blob becomes a table (#227). The blob is schemaless by design, so
 * what is under test is the honesty rules: known keys lead in reading order,
 * unknown keys print as themselves rather than being hidden, and absent stays
 * absent instead of becoming a zero that claims something.
 */

test("known keys lead in reading order, unknown keys follow alphabetically", () => {
  const rows = statRows({
    zeta: 1,
    energyCost: 800,
    health: 5000,
    metalCost: 200,
    alpha: 2,
  });
  expect(rows.map((row) => row.key)).toEqual([
    "health",
    "metalCost",
    "energyCost",
    "alpha",
    "zeta",
  ]);
});

test("an unknown key prints as itself", () => {
  expect(statLabel("shieldCapacity")).toBe("shieldCapacity");
  expect(statLabel("health")).toBe("Health");
});

test("absent stays absent, and structures arrive as data", () => {
  expect(formatStatValue(null)).toBe("-");
  expect(formatStatValue(undefined)).toBe("-");
  expect(formatStatValue(4500)).toBe("4500");
  expect(formatStatValue(true)).toBe("true");
  expect(formatStatValue("yes")).toBe("yes");
  expect(formatStatValue({ damage: 40, reload: 1.5 })).toBe('{"damage":40,"reload":1.5}');
});

/**
 * A weapons summary is an array of records, and JSON of that shape is what
 * readers saw instead of a table (#261).
 */

const WEAPONS = [
  { range: 300, damage: 75, reload: 0.4, projectile: "BeamLaser" },
  { range: 250, damage: 99999, projectile: "DGun" },
];

test("an array of flat records is tabular", () => {
  expect(tabularStatRows(WEAPONS)).toEqual(WEAPONS);
});

test("anything else keeps its compact JSON", () => {
  expect(tabularStatRows(300)).toBeNull();
  expect(tabularStatRows(["BeamLaser", "DGun"])).toBeNull();
  expect(tabularStatRows([])).toBeNull();
  expect(tabularStatRows([{ ok: true }, "not a record"])).toBeNull();
});

test("columns come back in first-appearance order, and every column survives", () => {
  // DGun carries no reload; the column stays because the BeamLaser does.
  expect(tabularColumns(WEAPONS)).toEqual(["range", "damage", "reload", "projectile"]);
});
